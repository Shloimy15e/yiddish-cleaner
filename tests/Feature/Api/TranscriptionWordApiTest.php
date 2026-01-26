<?php

namespace Tests\Feature\Api;

use App\Models\AudioSample;
use App\Models\Transcription;
use App\Models\TranscriptionWord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptionWordApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected AudioSample $audioSample;
    protected Transcription $transcription;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->audioSample = AudioSample::factory()->create(['user_id' => $this->user->id]);
        $this->transcription = Transcription::factory()->create([
            'audio_sample_id' => $this->audioSample->id,
            'type' => 'asr',
            'user_id' => $this->user->id,
        ]);
    }

    // ==================== Index Tests ====================

    public function test_can_fetch_words_for_transcription(): void
    {
        // Create some words
        TranscriptionWord::factory()->count(5)->create([
            'transcription_id' => $this->transcription->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/transcriptions/{$this->transcription->id}/words");

        $response->assertOk()
            ->assertJsonStructure([
                'words' => [
                    '*' => [
                        'id',
                        'transcription_id',
                        'word_index',
                        'word',
                        'start_time',
                        'end_time',
                        'confidence',
                        'corrected_word',
                        'is_deleted',
                        'is_inserted',
                    ],
                ],
                'stats' => [
                    'total_words',
                    'correction_count',
                    'correction_rate',
                    'deleted_count',
                    'inserted_count',
                    'low_confidence_count',
                ],
                'config' => [
                    'playback_padding_seconds',
                    'default_confidence_threshold',
                ],
            ]);

        $this->assertCount(5, $response->json('words'));
    }

    public function test_words_are_ordered_by_index(): void
    {
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word_index' => 3,
            'word' => 'third',
        ]);
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word_index' => 1,
            'word' => 'first',
        ]);
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word_index' => 2,
            'word' => 'second',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/transcriptions/{$this->transcription->id}/words");

        $words = $response->json('words');
        $this->assertEquals('first', $words[0]['word']);
        $this->assertEquals('second', $words[1]['word']);
        $this->assertEquals('third', $words[2]['word']);
    }

    public function test_stats_calculate_corrections_correctly(): void
    {
        // Create words with different states
        TranscriptionWord::factory()->count(3)->create([
            'transcription_id' => $this->transcription->id,
            'corrected_word' => null,
            'is_deleted' => false,
        ]);
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'corrected_word' => 'corrected',
            'is_deleted' => false,
        ]);
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'corrected_word' => null,
            'is_deleted' => true,
        ]);
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'is_inserted' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/transcriptions/{$this->transcription->id}/words");

        $stats = $response->json('stats');
        
        $this->assertEquals(5, $stats['total_words']); // Excludes inserted
        $this->assertEquals(3, $stats['correction_count']); // corrected + deleted + inserted
        $this->assertEquals(1, $stats['deleted_count']);
        $this->assertEquals(1, $stats['inserted_count']);
    }

    // ==================== Update Tests ====================

    public function test_can_correct_a_word(): void
    {
        $word = TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word' => 'original',
            'corrected_word' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/transcriptions/{$this->transcription->id}/words/{$word->id}", [
                'corrected_word' => 'corrected',
            ]);

        $response->assertOk()
            ->assertJsonPath('word.corrected_word', 'corrected');
        
        $this->assertDatabaseHas('transcription_words', [
            'id' => $word->id,
            'corrected_word' => 'corrected',
            'corrected_by' => $this->user->id,
        ]);
    }

    public function test_can_clear_correction(): void
    {
        $word = TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word' => 'original',
            'corrected_word' => 'was corrected',
            'corrected_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/transcriptions/{$this->transcription->id}/words/{$word->id}", [
                'corrected_word' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('word.corrected_word', null);
    }

    public function test_can_soft_delete_word(): void
    {
        $word = TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'is_deleted' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/transcriptions/{$this->transcription->id}/words/{$word->id}", [
                'is_deleted' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('word.is_deleted', true);
    }

    public function test_can_restore_deleted_word(): void
    {
        $word = TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'is_deleted' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/transcriptions/{$this->transcription->id}/words/{$word->id}", [
                'is_deleted' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('word.is_deleted', false);
    }

    public function test_cannot_update_word_from_different_transcription(): void
    {
        $otherTranscription = Transcription::factory()->create([
            'audio_sample_id' => $this->audioSample->id,
            'type' => 'asr',
        ]);
        $word = TranscriptionWord::factory()->create([
            'transcription_id' => $otherTranscription->id,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/transcriptions/{$this->transcription->id}/words/{$word->id}", [
                'corrected_word' => 'test',
            ]);

        $response->assertStatus(403);
    }

    // ==================== Store Tests ====================

    public function test_can_insert_word_after_existing_word(): void
    {
        $word1 = TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word_index' => 1,
            'start_time' => 0,
            'end_time' => 1,
        ]);
        $word2 = TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word_index' => 2,
            'start_time' => 1,
            'end_time' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/transcriptions/{$this->transcription->id}/words", [
                'word' => 'inserted',
                'after_word_id' => $word1->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('word.word', 'inserted')
            ->assertJsonPath('word.is_inserted', true);

        // Check word_index is between 1 and 2
        $insertedIndex = $response->json('word.word_index');
        $this->assertGreaterThan(1, $insertedIndex);
        $this->assertLessThan(2, $insertedIndex);
    }

    public function test_cannot_insert_word_for_wrong_transcription(): void
    {
        $otherTranscription = Transcription::factory()->create([
            'audio_sample_id' => $this->audioSample->id,
            'type' => 'asr',
        ]);
        $word = TranscriptionWord::factory()->create([
            'transcription_id' => $otherTranscription->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/transcriptions/{$this->transcription->id}/words", [
                'word' => 'test',
                'after_word_id' => $word->id,
            ]);

        $response->assertStatus(403);
    }

    // ==================== Destroy Tests ====================

    public function test_can_permanently_delete_inserted_word(): void
    {
        $word = TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'is_inserted' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/transcriptions/{$this->transcription->id}/words/{$word->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);
        
        $this->assertDatabaseMissing('transcription_words', ['id' => $word->id]);
    }

    public function test_cannot_permanently_delete_original_word(): void
    {
        $word = TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'is_inserted' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/transcriptions/{$this->transcription->id}/words/{$word->id}");

        $response->assertStatus(400);
        
        $this->assertDatabaseHas('transcription_words', ['id' => $word->id]);
    }

    // ==================== Training Flag Tests ====================

    public function test_can_toggle_training_flag(): void
    {
        $this->assertFalse($this->transcription->flagged_for_training);

        $response = $this->actingAs($this->user)
            ->postJson("/api/transcriptions/{$this->transcription->id}/toggle-training");

        $response->assertOk()
            ->assertJsonPath('flagged_for_training', true);

        $this->transcription->refresh();
        $this->assertTrue($this->transcription->flagged_for_training);

        // Toggle again
        $response = $this->actingAs($this->user)
            ->postJson("/api/transcriptions/{$this->transcription->id}/toggle-training");

        $response->assertOk()
            ->assertJsonPath('flagged_for_training', false);
    }

    // ==================== Corrected Text Tests ====================

    public function test_can_get_corrected_text(): void
    {
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word_index' => 1,
            'word' => 'original1',
            'corrected_word' => null,
            'is_deleted' => false,
        ]);
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word_index' => 2,
            'word' => 'wrong',
            'corrected_word' => 'correct',
            'is_deleted' => false,
        ]);
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word_index' => 3,
            'word' => 'deleted',
            'corrected_word' => null,
            'is_deleted' => true,
        ]);
        TranscriptionWord::factory()->create([
            'transcription_id' => $this->transcription->id,
            'word_index' => 2.5, // Inserted between 2 and 3
            'word' => 'inserted',
            'is_inserted' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/transcriptions/{$this->transcription->id}/corrected-text");

        $response->assertOk()
            ->assertJsonPath('corrected_text', 'original1 correct inserted');
    }
}
