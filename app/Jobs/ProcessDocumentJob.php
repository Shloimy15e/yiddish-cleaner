<?php

namespace App\Jobs;

use App\Events\DocumentProcessed;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Services\Cleaning\CleanerService;
use App\Services\Cleaning\CleanRateCalculator;
use App\Services\Document\ParserService;
use App\Services\Llm\LlmManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        public Document $document,
        public ?string $filePath = null,
    ) {}

    public function handle(
        ParserService $parser,
        CleanerService $cleaner,
        CleanRateCalculator $calculator,
        LlmManager $llm,
    ): void {
        $run = $this->document->processingRun;

        try {
            $this->document->update(['status' => 'processing']);

            // Extract text from file if provided
            $text = $this->filePath
                ? $parser->extractText($this->filePath)
                : $this->document->original_text;

            if (!$text) {
                throw new \RuntimeException('No text to process');
            }

            // Store original text
            $this->document->update(['original_text' => $text]);

            // Clean based on mode
            if ($run->mode === 'llm') {
                $user = $run->user;
                $credential = $user->getApiCredential('openai', 'llm'); // Default to OpenAI
                $cleanedText = $llm->clean($text, 'openai', $credential);

                // Still calculate metrics
                $result = new \App\Services\Cleaning\CleaningResult(
                    originalText: $text,
                    cleanedText: $cleanedText,
                    removals: [],
                    processorResults: ['llm' => ['changes' => 1, 'removals' => []]],
                );
            } else {
                $result = $cleaner->cleanWithPreset($text, $run->preset);
            }

            // Calculate clean rate
            $cleanRate = $calculator->calculate($result);

            // Update document
            $this->document->update([
                'original_text' => $result->originalText,
                'cleaned_text' => $result->cleanedText,
                'original_hash' => $result->getOriginalHash(),
                'cleaned_hash' => $result->getCleanedHash(),
                'clean_rate' => $cleanRate->score,
                'clean_rate_category' => $cleanRate->category,
                'metrics' => $result->getMetrics(),
                'removals' => $result->removals,
                'status' => 'completed',
            ]);

            $run->incrementCompleted();

            // Cleanup temp file
            if ($this->filePath) {
                $parser->cleanup($this->filePath);
            }

        } catch (Throwable $e) {
            $this->document->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $run->incrementFailed();
        }

        // Broadcast progress
        event(new DocumentProcessed($run->fresh(), $this->document->fresh()));
    }

    public function failed(Throwable $exception): void
    {
        $this->document->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);

        $this->document->processingRun->incrementFailed();
    }
}
