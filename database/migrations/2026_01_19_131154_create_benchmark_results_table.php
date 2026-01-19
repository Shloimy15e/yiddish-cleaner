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
        Schema::create('benchmark_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model_name');
            $table->string('model_version')->nullable();
            $table->longText('transcribed_text')->nullable();
            $table->string('transcribed_hash', 64)->nullable();
            $table->float('wer')->nullable(); // Word Error Rate 0-100
            $table->float('cer')->nullable(); // Character Error Rate 0-100
            $table->integer('substitutions')->default(0);
            $table->integer('insertions')->default(0);
            $table->integer('deletions')->default(0);
            $table->integer('reference_words')->default(0);
            $table->json('errors')->nullable(); // detailed error breakdown
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'model_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benchmark_results');
    }
};
