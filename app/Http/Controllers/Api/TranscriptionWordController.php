<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transcription;
use App\Models\TranscriptionWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class TranscriptionWordController extends Controller
{
    /**
     * Calculate stats for a collection of words.
     */
    private function calculateStats(Collection $words): array
    {
        $totalWords = $words->where('is_inserted', false)->count();
        $correctionCount = $words->filter(fn ($w) => $w->isCorrected())->count();

        return [
            'total_words' => $totalWords,
            'correction_count' => $correctionCount,
            'correction_rate' => $totalWords > 0 ? $correctionCount / $totalWords : 0,
            'deleted_count' => $words->where('is_deleted', true)->count(),
            'inserted_count' => $words->where('is_inserted', true)->count(),
            'low_confidence_count' => $words
                ->whereNotNull('confidence')
                ->where('confidence', '<=', config('asr.review.default_confidence_threshold', 0.7))
                ->count(),
        ];
    }

    /**
     * Get all words for a transcription with correction stats.
     */
    public function index(Transcription $transcription): JsonResponse
    {
        $words = $transcription->words()
            ->orderBy('word_index')
            ->get();

        return response()->json([
            'words' => $words,
            'stats' => $this->calculateStats($words),
            'config' => [
                'playback_padding_seconds' => config('asr.review.playback_padding_seconds', 2.0),
                'default_confidence_threshold' => config('asr.review.default_confidence_threshold', 0.7),
            ],
        ]);
    }

    /**
     * Update a word (edit or delete).
     */
    public function update(Request $request, Transcription $transcription, TranscriptionWord $word): JsonResponse
    {
        // Ensure word belongs to transcription
        if ($word->transcription_id !== $transcription->id) {
            return response()->json(['error' => 'Word does not belong to this transcription'], 403);
        }

        $validated = $request->validate([
            'corrected_word' => 'nullable|string|max:500',
            'is_deleted' => 'nullable|boolean',
            'clear_correction' => 'nullable|boolean',
        ]);

        $userId = Auth::id();

        // Clear correction
        if ($validated['clear_correction'] ?? false) {
            $word->clearCorrection();
        }
        // Mark as deleted
        elseif ($validated['is_deleted'] ?? false) {
            $word->markDeleted($userId);
        }
        // Restore (undelete)
        elseif (isset($validated['is_deleted']) && $validated['is_deleted'] === false) {
            $word->update([
                'is_deleted' => false,
                'corrected_word' => $validated['corrected_word'] ?? null,
            ]);
        }
        // Apply correction
        elseif (array_key_exists('corrected_word', $validated)) {
            $word->applyCorrection($validated['corrected_word'], $userId);
        }

        $words = $transcription->words()->get();

        return response()->json([
            'word' => $word->fresh(),
            'stats' => $this->calculateStats($words),
        ]);
    }

    /**
     * Insert a new word between existing words.
     */
    public function store(Request $request, Transcription $transcription): JsonResponse
    {
        $validated = $request->validate([
            'word' => 'required|string|max:500',
            'after_word_id' => 'required|integer|exists:transcription_words,id',
        ]);

        $afterWord = TranscriptionWord::find($validated['after_word_id']);

        // Ensure word belongs to transcription
        if ($afterWord->transcription_id !== $transcription->id) {
            return response()->json(['error' => 'Word does not belong to this transcription'], 403);
        }

        // Find the next word (if any)
        $nextWord = $transcription->words()
            ->where('word_index', '>', $afterWord->word_index)
            ->orderBy('word_index')
            ->first();

        $newWord = TranscriptionWord::insertBetween(
            $transcription,
            $validated['word'],
            $afterWord,
            $nextWord,
            Auth::id()
        );

        $words = $transcription->words()->get();

        return response()->json([
            'word' => $newWord,
            'stats' => $this->calculateStats($words),
        ], 201);
    }

    /**
     * Insert a word at the beginning of the transcription.
     */
    public function storeAtStart(Request $request, Transcription $transcription): JsonResponse
    {
        $validated = $request->validate([
            'word' => 'required|string|max:500',
        ]);

        $firstWord = $transcription->words()
            ->orderBy('word_index')
            ->first();

        if (! $firstWord) {
            return response()->json(['error' => 'Transcription has no words'], 400);
        }

        // Create word with index before first word
        $newIndex = (float) $firstWord->word_index - 1;
        
        // Calculate timing (start at 0, end at first word's start)
        $endTime = (float) $firstWord->start_time;
        $startTime = max(0, $endTime - 0.3);

        $newWord = TranscriptionWord::create([
            'transcription_id' => $transcription->id,
            'word_index' => $newIndex,
            'word' => $validated['word'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'confidence' => null,
            'is_inserted' => true,
            'corrected_by' => Auth::id(),
            'corrected_at' => now(),
        ]);

        $words = $transcription->words()->get();

        return response()->json([
            'word' => $newWord,
            'stats' => $this->calculateStats($words),
        ], 201);
    }

    /**
     * Delete an inserted word permanently.
     * Note: Original ASR words should use soft delete (is_deleted flag) instead.
     */
    public function destroy(Transcription $transcription, TranscriptionWord $word): JsonResponse
    {
        // Ensure word belongs to transcription
        if ($word->transcription_id !== $transcription->id) {
            return response()->json(['error' => 'Word does not belong to this transcription'], 403);
        }

        // Only allow permanent deletion of inserted words
        if (! $word->is_inserted) {
            return response()->json([
                'error' => 'Original ASR words cannot be permanently deleted. Use is_deleted flag instead.',
            ], 400);
        }

        $word->delete();

        $words = $transcription->words()->get();

        return response()->json([
            'success' => true,
            'stats' => $this->calculateStats($words),
        ]);
    }

    /**
     * Toggle training flag on transcription.
     */
    public function toggleTrainingFlag(Transcription $transcription): JsonResponse
    {
        $transcription->update([
            'flagged_for_training' => ! $transcription->flagged_for_training,
        ]);

        return response()->json([
            'flagged_for_training' => $transcription->flagged_for_training,
        ]);
    }

    /**
     * Get the corrected text for a transcription.
     */
    public function getCorrectedText(Transcription $transcription): JsonResponse
    {
        return response()->json([
            'original_text' => $transcription->hypothesis_text,
            'corrected_text' => $transcription->getCorrectedText(),
            'correction_count' => $transcription->getCorrectionCount(),
            'correction_rate' => $transcription->getCorrectionRate(),
        ]);
    }
}
