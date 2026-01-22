<?php

use App\Models\AudioSample;
use App\Models\Transcription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration:
     * 1. Migrates existing AudioSample transcript data to Transcription records (type='base')
     * 2. Copies media from AudioSample to Transcription
     * 3. Updates AudioSample status values to new workflow
     * 4. Removes transcript-related columns from audio_samples table
     */
    public function up(): void
    {
        // Step 1: Migrate existing transcript data from audio_samples to transcriptions
        // Use raw query to get samples since columns will be dropped later
        $audioSampleData = DB::table('audio_samples')
            ->whereNotNull('reference_text_raw')
            ->orWhereNotNull('reference_text_clean')
            ->get();

        foreach ($audioSampleData as $sampleData) {
            // Determine status for the base transcription
            $transcriptionStatus = 'pending';
            if ($sampleData->validated_at) {
                $transcriptionStatus = 'completed';
            } elseif ($sampleData->reference_text_clean) {
                $transcriptionStatus = 'completed';
            }

            // Create the Transcription record using the model
            $transcription = Transcription::create([
                'type' => Transcription::TYPE_BASE,
                'name' => $sampleData->name,
                'audio_sample_id' => $sampleData->id,
                'text_raw' => $sampleData->reference_text_raw,
                'text_clean' => $sampleData->reference_text_clean,
                'hash_raw' => $sampleData->reference_hash_raw,
                'hash_clean' => $sampleData->reference_hash_clean,
                'clean_rate' => $sampleData->clean_rate,
                'clean_rate_category' => $sampleData->clean_rate_category,
                'metrics' => $sampleData->metrics ? json_decode($sampleData->metrics, true) : null,
                'removals' => $sampleData->removals ? json_decode($sampleData->removals, true) : null,
                'validated_at' => $sampleData->validated_at,
                'validated_by' => $sampleData->validated_by,
                'review_notes' => $sampleData->review_notes,
                'status' => $transcriptionStatus,
                'source' => Transcription::SOURCE_IMPORTED,
                'created_at' => $sampleData->created_at,
            ]);

            // Copy media from AudioSample to Transcription
            $audioSample = AudioSample::find($sampleData->id);
            if ($audioSample) {
                $referenceMedia = $audioSample->getFirstMedia('reference_transcript');
                if ($referenceMedia) {
                    // Copy the media file to the new transcription
                    $referenceMedia->copy($transcription, 'source_file');
                }
            }
        }

        // Step 2: Update AudioSample statuses to new workflow
        // Map old statuses to new statuses
        $statusMapping = [
            'pending_transcript' => 'pending_base',  // No base transcription
            'imported' => 'unclean',                 // Has base but not validated
            'cleaning' => 'unclean',                 // Still unclean during cleaning
            'cleaned' => 'unclean',                  // Cleaned but not validated
            'validated' => 'ready',                  // Base is validated, ready for ASR
            'failed' => 'unclean',                   // Failed cleaning, needs attention
        ];

        foreach ($statusMapping as $old => $new) {
            DB::table('audio_samples')
                ->where('status', $old)
                ->update(['status' => $new]);
        }

        // Samples without any base transcription should be pending_base
        DB::table('audio_samples')
            ->whereNull('reference_text_raw')
            ->whereNull('reference_text_clean')
            ->update(['status' => 'pending_base']);

        // Step 3: Remove transcript-related columns from audio_samples
        Schema::table('audio_samples', function (Blueprint $table) {
            $table->dropColumn([
                'reference_text_raw',
                'reference_text_clean',
                'reference_hash_raw',
                'reference_hash_clean',
                'clean_rate',
                'clean_rate_category',
                'metrics',
                'removals',
                'validated_at',
                'validated_by',
                'review_notes',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Re-add columns to audio_samples
        Schema::table('audio_samples', function (Blueprint $table) {
            $table->longText('reference_text_raw')->nullable();
            $table->longText('reference_text_clean')->nullable();
            $table->string('reference_hash_raw', 64)->nullable();
            $table->string('reference_hash_clean', 64)->nullable();
            $table->unsignedTinyInteger('clean_rate')->nullable();
            $table->string('clean_rate_category')->nullable();
            $table->json('metrics')->nullable();
            $table->json('removals')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('validated_by')->nullable();
            $table->text('review_notes')->nullable();
        });

        // Step 2: Migrate data and media back from transcriptions to audio_samples
        $baseTranscriptions = Transcription::with('media')
            ->where('type', Transcription::TYPE_BASE)
            ->whereNotNull('audio_sample_id')
            ->get();

        foreach ($baseTranscriptions as $transcription) {
            // Update the audio sample data
            DB::table('audio_samples')
                ->where('id', $transcription->audio_sample_id)
                ->update([
                    'reference_text_raw' => $transcription->text_raw,
                    'reference_text_clean' => $transcription->text_clean,
                    'reference_hash_raw' => $transcription->hash_raw,
                    'reference_hash_clean' => $transcription->hash_clean,
                    'clean_rate' => $transcription->clean_rate,
                    'clean_rate_category' => $transcription->clean_rate_category,
                    'metrics' => $transcription->metrics ? json_encode($transcription->metrics) : null,
                    'removals' => $transcription->removals ? json_encode($transcription->removals) : null,
                    'validated_at' => $transcription->validated_at,
                    'validated_by' => $transcription->validated_by,
                    'review_notes' => $transcription->review_notes,
                ]);

            // Copy media back from Transcription to AudioSample
            $audioSample = AudioSample::find($transcription->audio_sample_id);
            if ($audioSample) {
                $sourceMedia = $transcription->getFirstMedia('source_file');
                if ($sourceMedia) {
                    $sourceMedia->copy($audioSample, 'reference_transcript');
                }
            }
        }

        // Step 3: Reverse status mapping
        $statusMapping = [
            'draft' => 'pending_transcript',
            'pending_base' => 'pending_transcript',
            'unclean' => 'imported',
            'ready' => 'validated',
            'benchmarked' => 'validated',
        ];

        foreach ($statusMapping as $new => $old) {
            DB::table('audio_samples')
                ->where('status', $new)
                ->update(['status' => $old]);
        }

        // Step 4: Delete base transcriptions (this will also delete their media)
        Transcription::where('type', Transcription::TYPE_BASE)->each(function ($transcription) {
            $transcription->delete();
        });
    }
};
