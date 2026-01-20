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
        Schema::table('google_credentials', function (Blueprint $table) {
            // Change from json to text to store encrypted values
            $table->text('token')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_credentials', function (Blueprint $table) {
            $table->json('token')->change();
        });
    }
};
