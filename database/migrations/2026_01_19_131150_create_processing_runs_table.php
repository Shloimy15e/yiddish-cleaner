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
        Schema::create('processing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('batch_id')->unique();
            $table->string('preset'); // titles_only, full_clean, etc.
            $table->string('mode')->default('rule'); // rule, llm
            $table->string('source_type'); // upload, drive, sheet
            $table->text('source_url')->nullable();
            $table->integer('total')->default(0);
            $table->integer('completed')->default(0);
            $table->integer('failed')->default(0);
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processing_runs');
    }
};
