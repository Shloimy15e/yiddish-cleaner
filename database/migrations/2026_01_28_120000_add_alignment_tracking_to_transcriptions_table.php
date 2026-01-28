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
            // Alignment status tracking
            $table->string('alignment_status')->nullable()->after('status');
            $table->text('alignment_error')->nullable()->after('alignment_status');
            
            // Alignment metadata
            $table->string('alignment_provider')->nullable()->after('alignment_error');
            $table->string('alignment_model')->nullable()->after('alignment_provider');
            
            // Alignment timestamps
            $table->timestamp('alignment_started_at')->nullable()->after('alignment_model');
            $table->timestamp('alignment_completed_at')->nullable()->after('alignment_started_at');
            
            // Retry tracking
            $table->unsignedTinyInteger('alignment_attempts')->default(0)->after('alignment_completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn([
                'alignment_status',
                'alignment_error',
                'alignment_provider',
                'alignment_model',
                'alignment_started_at',
                'alignment_completed_at',
                'alignment_attempts',
            ]);
        });
    }
};
