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
        Schema::create('audio_sample_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_sample_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('action'); // e.g., 'created', 'cleaned', 'edited', 'validated', 'transcript_replaced'
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable(); // Store additional context like edit diff, cleaning settings, etc.
            $table->timestamps();

            $table->index(['audio_sample_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_sample_status_history');
    }
};
