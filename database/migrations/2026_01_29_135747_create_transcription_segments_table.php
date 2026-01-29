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
        Schema::create('transcription_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcription_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('segment_index');
            $table->text('text');
            $table->text('corrected_text')->nullable();
            $table->decimal('start_time', 10, 3);
            $table->decimal('end_time', 10, 3);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('words_json')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();

            $table->index(['transcription_id', 'segment_index']);
            $table->index(['transcription_id', 'confidence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcription_segments');
    }
};
