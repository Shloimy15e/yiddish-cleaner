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
        Schema::table('processing_runs', function (Blueprint $table) {
            $table->string('llm_provider')->nullable()->after('mode');
            $table->string('llm_model')->nullable()->after('llm_provider');
            $table->json('options')->nullable()->after('source_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processing_runs', function (Blueprint $table) {
            $table->dropColumn(['llm_provider', 'llm_model', 'options']);
        });
    }
};
