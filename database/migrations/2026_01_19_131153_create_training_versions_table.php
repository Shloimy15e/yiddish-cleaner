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
        Schema::create('training_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('version'); // v1.0, v1.1, etc.
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('criteria')->nullable(); // min_clean_rate, validated_only, etc.
            $table->integer('document_count')->default(0);
            $table->integer('word_count')->default(0);
            $table->float('total_audio_hours')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->string('export_format')->nullable(); // kaldi, json, csv
            $table->text('export_path')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_versions');
    }
};
