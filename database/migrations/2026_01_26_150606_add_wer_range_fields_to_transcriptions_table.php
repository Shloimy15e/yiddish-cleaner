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
        Schema::table('transcriptions', function (Blueprint $table) {
            // WER calculation range - word indices (0-based, inclusive)
            // For reference text (base transcription's text_clean)
            $table->unsignedInteger('wer_ref_start')->nullable()->after('errors');
            $table->unsignedInteger('wer_ref_end')->nullable()->after('wer_ref_start');
            
            // For hypothesis text (ASR output)
            $table->unsignedInteger('wer_hyp_start')->nullable()->after('wer_ref_end');
            $table->unsignedInteger('wer_hyp_end')->nullable()->after('wer_hyp_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn([
                'wer_ref_start',
                'wer_ref_end',
                'wer_hyp_start',
                'wer_hyp_end',
            ]);
        });
    }
};
