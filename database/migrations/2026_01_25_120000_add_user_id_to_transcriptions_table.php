<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add user_id to audio_samples
        Schema::table('audio_samples', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        // Migrate audio_samples: get user_id from processing_run
        DB::statement('
            UPDATE audio_samples
            SET user_id = (
                SELECT processing_runs.user_id
                FROM processing_runs
                WHERE processing_runs.id = audio_samples.processing_run_id
            )
            WHERE processing_run_id IS NOT NULL
        ');

        // Fallback for audio_samples without processing_run
        $fallbackUserId = User::first()?->id;
        if ($fallbackUserId) {
            DB::table('audio_samples')
                ->whereNull('user_id')
                ->update(['user_id' => $fallbackUserId]);
        }

        // Add user_id to transcriptions
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        // Migrate transcriptions: get user_id from linked audio_sample
        DB::statement('
            UPDATE transcriptions
            SET user_id = (
                SELECT audio_samples.user_id
                FROM audio_samples
                WHERE audio_samples.id = transcriptions.audio_sample_id
            )
            WHERE audio_sample_id IS NOT NULL
        ');

        // Fallback for transcriptions without audio_sample
        if ($fallbackUserId) {
            DB::table('transcriptions')
                ->whereNull('user_id')
                ->update(['user_id' => $fallbackUserId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('audio_samples', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
