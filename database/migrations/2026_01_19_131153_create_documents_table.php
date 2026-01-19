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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_run_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('source_url')->nullable();
            $table->string('original_hash', 64)->nullable();
            $table->string('cleaned_hash', 64)->nullable();
            $table->longText('original_text')->nullable();
            $table->longText('cleaned_text')->nullable();
            $table->text('audio_link')->nullable();
            $table->float('audio_length')->nullable();
            $table->integer('clean_rate')->nullable(); // 0-100
            $table->string('clean_rate_category')->nullable(); // excellent, good, moderate, low, poor
            $table->json('metrics')->nullable(); // word counts, char counts, etc.
            $table->json('removals')->nullable(); // what was removed
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('validated_by')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index('original_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
