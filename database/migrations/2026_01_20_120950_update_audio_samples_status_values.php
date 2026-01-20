<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Old statuses: pending, processing, completed, failed
     * New statuses: pending_transcript, imported, cleaning, cleaned, validated, failed
     */
    public function up(): void
    {
        // Migrate existing status values to new ones
        // 'completed' with reference_text_clean → 'imported' (to force re-review)
        // 'completed' without clean text → 'imported'
        // 'pending' → 'imported' (if has raw text) or 'pending_transcript'
        // 'processing' → 'imported' (reset stuck jobs)
        // 'failed' → 'failed' (unchanged)

        DB::table('audio_samples')
            ->where('status', 'completed')
            ->update(['status' => 'imported']);

        DB::table('audio_samples')
            ->where('status', 'processing')
            ->update(['status' => 'imported']);

        DB::table('audio_samples')
            ->where('status', 'pending')
            ->whereNotNull('reference_text_raw')
            ->update(['status' => 'imported']);

        DB::table('audio_samples')
            ->where('status', 'pending')
            ->whereNull('reference_text_raw')
            ->update(['status' => 'pending_transcript']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to old status values
        DB::table('audio_samples')
            ->whereIn('status', ['imported', 'cleaning', 'cleaned', 'validated'])
            ->update(['status' => 'completed']);

        DB::table('audio_samples')
            ->where('status', 'pending_transcript')
            ->update(['status' => 'pending']);
    }
};
