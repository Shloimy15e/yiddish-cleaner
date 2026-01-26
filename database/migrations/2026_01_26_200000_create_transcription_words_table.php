<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create transcription_words table for word-level ASR data
        Schema::create('transcription_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcription_id')->constrained()->cascadeOnDelete();
            
            // Word index - using decimal to allow insertions (e.g., 5.5 between 5 and 6)
            $table->decimal('word_index', 10, 4);
            
            // Original word from ASR
            $table->string('word', 500);
            
            // Timing data (in seconds)
            $table->decimal('start_time', 10, 3);
            $table->decimal('end_time', 10, 3);
            
            // Confidence score from ASR (0-1 scale, nullable for providers that don't support it)
            $table->decimal('confidence', 4, 3)->nullable();
            
            // Correction data
            $table->string('corrected_word', 500)->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->boolean('is_inserted')->default(false); // For user-added words
            
            // Who made the correction
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            
            $table->timestamps();
            
            // Index for efficient queries
            $table->index(['transcription_id', 'word_index']);
            $table->index(['transcription_id', 'confidence']);
            $table->index(['transcription_id', 'corrected_word']); // For counting corrections
        });

        // Add flagged_for_training to transcriptions table
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->boolean('flagged_for_training')->default(false)->after('errors');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcription_words');

        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn('flagged_for_training');
        });
    }
};
