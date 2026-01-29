<?php

namespace App\Console\Commands;

use App\Models\Transcription;
use App\Models\TranscriptionWord;
use Illuminate\Console\Command;

class BackfillYiddishLabsWords extends Command
{
    protected $signature = 'transcriptions:backfill-words 
                            {--provider=yiddishlabs : ASR provider to backfill (matches model_name prefix)}
                            {--force : Overwrite existing word data}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Backfill word-level data for transcriptions that only have text/segments';

    public function handle(): int
    {
        $provider = $this->option('provider');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $this->info("Backfilling word data for {$provider} transcriptions...");

        // Filter by model_name which is stored as "provider/model"
        $query = Transcription::query()
            ->where('model_name', 'LIKE', $provider.'/%')
            ->where('status', Transcription::STATUS_COMPLETED)
            ->whereNotNull('hypothesis_text')
            ->where('hypothesis_text', '!=', '');

        // Only include those without word data (unless force)
        if (! $force) {
            $query->whereDoesntHave('words');
        }

        $transcriptions = $query->get();

        $this->info("Found {$transcriptions->count()} transcriptions to process");

        if ($transcriptions->isEmpty()) {
            $this->info('No transcriptions need backfilling.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($transcriptions->count());
        $bar->start();

        $stats = [
            'from_segments' => 0,
            'from_metadata' => 0,
            'from_text' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($transcriptions as $transcription) {
            try {
                $result = $this->backfillTranscription($transcription, $dryRun);
                $stats[$result]++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->newLine();
                $this->error("Error processing Transcription #{$transcription->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Backfill complete:');
        $this->table(
            ['Source', 'Count'],
            [
                ['From segments (words_json)', $stats['from_segments']],
                ['From metadata (raw_timestamped_text)', $stats['from_metadata']],
                ['From plain text (estimated)', $stats['from_text']],
                ['Skipped (no data)', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );

        return self::SUCCESS;
    }

    protected function backfillTranscription(Transcription $transcription, bool $dryRun): string
    {
        // Priority 1: Try to get words from segments with embedded word data
        if ($transcription->hasSegmentData()) {
            $words = $this->getWordsFromSegments($transcription);
            if (! empty($words)) {
                if (! $dryRun) {
                    $this->storeWords($transcription, $words);
                }

                return 'from_segments';
            }
        }

        // Priority 2: Try to parse from raw_timestamped_text in metadata
        $metadata = $transcription->metrics ?? [];
        $rawTimestampedText = $metadata['raw_timestamped_text'] ?? null;
        if ($rawTimestampedText) {
            $words = $this->parseTimestampedText($rawTimestampedText);
            if (! empty($words)) {
                if (! $dryRun) {
                    $this->storeWords($transcription, $words);
                }

                return 'from_metadata';
            }
        }

        // Priority 3: Generate estimated words from plain text
        $text = $transcription->hypothesis_text;
        $duration = $transcription->audioSample?->audio_duration_seconds ?? $metadata['duration_seconds'] ?? null;

        if ($text) {
            $words = $this->generateWordsFromText($text, $duration);
            if (! empty($words)) {
                if (! $dryRun) {
                    $this->storeWords($transcription, $words);
                }

                return 'from_text';
            }
        }

        return 'skipped';
    }

    /**
     * Extract words from segments that have embedded word data.
     *
     * @return array<array{word: string, start: float, end: float, confidence: float|null}>
     */
    protected function getWordsFromSegments(Transcription $transcription): array
    {
        $words = [];

        $segments = $transcription->segments()->orderBy('segment_index')->get();

        foreach ($segments as $segment) {
            $segmentWords = $segment->words_json ?? [];

            if (empty($segmentWords)) {
                // No embedded words - estimate from segment text
                $segmentText = $segment->text;
                $textWords = preg_split('/\s+/', $segmentText, -1, PREG_SPLIT_NO_EMPTY);

                if (empty($textWords)) {
                    continue;
                }

                $segmentDuration = $segment->end_time - $segment->start_time;
                $wordDuration = count($textWords) > 0 ? $segmentDuration / count($textWords) : 0;

                foreach ($textWords as $i => $word) {
                    $wordStart = $segment->start_time + ($i * $wordDuration);
                    $words[] = [
                        'word' => $word,
                        'start' => $wordStart,
                        'end' => $wordStart + $wordDuration,
                        'confidence' => $segment->confidence,
                    ];
                }
            } else {
                // Use embedded word data
                foreach ($segmentWords as $wordData) {
                    $words[] = [
                        'word' => $wordData['word'] ?? '',
                        'start' => $wordData['start'] ?? 0,
                        'end' => $wordData['end'] ?? 0,
                        'confidence' => $wordData['confidence'] ?? $segment->confidence,
                    ];
                }
            }
        }

        return $words;
    }

    /**
     * Parse timestamped text into word data (same logic as YiddishLabsDriver).
     *
     * @return array<array{word: string, start: float, end: float, confidence: float|null}>
     */
    protected function parseTimestampedText(string $text): array
    {
        $words = [];

        // Try format: [HH:MM:SS.mmm] or [MM:SS.mmm]
        $pattern1 = '/\[(?:(\d{1,2}):)?(\d{1,2}):(\d{1,2})\.(\d{1,3})\]\s*([^\[\]]+)/';

        // Try format: <seconds.milliseconds>
        $pattern2 = '/<(\d+\.?\d*)>\s*([^<]+)/';

        // Try format: (seconds.milliseconds)
        $pattern3 = '/\((\d+\.?\d*)\)\s*([^()]+)/';

        if (preg_match_all($pattern1, $text, $matches, PREG_SET_ORDER)) {
            for ($i = 0; $i < count($matches); $i++) {
                $hours = ! empty($matches[$i][1]) ? (int) $matches[$i][1] : 0;
                $minutes = (int) $matches[$i][2];
                $seconds = (int) $matches[$i][3];
                $milliseconds = (int) str_pad($matches[$i][4], 3, '0');

                $startTime = $hours * 3600 + $minutes * 60 + $seconds + $milliseconds / 1000;
                $wordsInSegment = preg_split('/\s+/', trim($matches[$i][5]), -1, PREG_SPLIT_NO_EMPTY);

                $endTime = isset($matches[$i + 1])
                    ? $this->parseBracketTimestamp($matches[$i + 1])
                    : $startTime + (count($wordsInSegment) * 0.3);

                $wordDuration = count($wordsInSegment) > 0
                    ? ($endTime - $startTime) / count($wordsInSegment)
                    : 0;

                foreach ($wordsInSegment as $j => $word) {
                    $wordStart = $startTime + ($j * $wordDuration);
                    $words[] = [
                        'word' => $word,
                        'start' => $wordStart,
                        'end' => $wordStart + $wordDuration,
                        'confidence' => null,
                    ];
                }
            }
        } elseif (preg_match_all($pattern2, $text, $matches, PREG_SET_ORDER)) {
            for ($i = 0; $i < count($matches); $i++) {
                $startTime = (float) $matches[$i][1];
                $wordsInSegment = preg_split('/\s+/', trim($matches[$i][2]), -1, PREG_SPLIT_NO_EMPTY);

                $endTime = isset($matches[$i + 1])
                    ? (float) $matches[$i + 1][1]
                    : $startTime + (count($wordsInSegment) * 0.3);

                $wordDuration = count($wordsInSegment) > 0
                    ? ($endTime - $startTime) / count($wordsInSegment)
                    : 0;

                foreach ($wordsInSegment as $j => $word) {
                    $wordStart = $startTime + ($j * $wordDuration);
                    $words[] = [
                        'word' => $word,
                        'start' => $wordStart,
                        'end' => $wordStart + $wordDuration,
                        'confidence' => null,
                    ];
                }
            }
        } elseif (preg_match_all($pattern3, $text, $matches, PREG_SET_ORDER)) {
            for ($i = 0; $i < count($matches); $i++) {
                $startTime = (float) $matches[$i][1];
                $wordsInSegment = preg_split('/\s+/', trim($matches[$i][2]), -1, PREG_SPLIT_NO_EMPTY);

                $endTime = isset($matches[$i + 1])
                    ? (float) $matches[$i + 1][1]
                    : $startTime + (count($wordsInSegment) * 0.3);

                $wordDuration = count($wordsInSegment) > 0
                    ? ($endTime - $startTime) / count($wordsInSegment)
                    : 0;

                foreach ($wordsInSegment as $j => $word) {
                    $wordStart = $startTime + ($j * $wordDuration);
                    $words[] = [
                        'word' => $word,
                        'start' => $wordStart,
                        'end' => $wordStart + $wordDuration,
                        'confidence' => null,
                    ];
                }
            }
        }

        return $words;
    }

    protected function parseBracketTimestamp(array $match): float
    {
        $hours = ! empty($match[1]) ? (int) $match[1] : 0;
        $minutes = (int) $match[2];
        $seconds = (int) $match[3];
        $milliseconds = (int) str_pad($match[4], 3, '0');

        return $hours * 3600 + $minutes * 60 + $seconds + $milliseconds / 1000;
    }

    /**
     * Generate word data from plain text with estimated timing based on duration.
     *
     * @return array<array{word: string, start: float, end: float, confidence: float|null}>
     */
    protected function generateWordsFromText(string $text, ?float $duration): array
    {
        $textWords = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($textWords)) {
            return [];
        }

        // If no duration provided, estimate based on average speaking rate
        // Average speaking rate is ~150 words/minute = 2.5 words/second = 0.4s per word
        if ($duration === null) {
            $duration = count($textWords) * 0.4;
        }

        // Calculate total "weight" based on word length (longer words take more time)
        $totalWeight = 0;
        $weights = [];
        foreach ($textWords as $word) {
            // Weight = base + character count (min 1)
            $weight = 1 + mb_strlen($word);
            $weights[] = $weight;
            $totalWeight += $weight;
        }

        $words = [];
        $currentTime = 0.0;

        foreach ($textWords as $i => $word) {
            $wordDuration = ($weights[$i] / $totalWeight) * $duration;
            $words[] = [
                'word' => $word,
                'start' => $currentTime,
                'end' => $currentTime + $wordDuration,
                'confidence' => null,
            ];
            $currentTime += $wordDuration;
        }

        return $words;
    }

    /**
     * Store word data in the database.
     *
     * @param  array<array{word: string, start: float, end: float, confidence: float|null}>  $words
     */
    protected function storeWords(Transcription $transcription, array $words): void
    {
        // Clear existing words
        $transcription->words()->delete();

        // Insert new words in batches
        $batch = [];
        foreach ($words as $index => $wordData) {
            $batch[] = [
                'transcription_id' => $transcription->id,
                'word_index' => $index,
                'word' => $wordData['word'],
                'start_time' => $wordData['start'],
                'end_time' => $wordData['end'],
                'confidence' => $wordData['confidence'],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Insert in batches of 100
            if (count($batch) >= 100) {
                TranscriptionWord::insert($batch);
                $batch = [];
            }
        }

        // Insert remaining
        if (! empty($batch)) {
            TranscriptionWord::insert($batch);
        }
    }
}
