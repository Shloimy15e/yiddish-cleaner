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
        Schema::table('transcription_words', function (Blueprint $table) {
            $table->boolean('is_critical_error')->default(false)->after('is_inserted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_words', function (Blueprint $table) {
            $table->dropColumn('is_critical_error');
        });
    }
};
