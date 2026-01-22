<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Drop the existing check constraint
            DB::statement('ALTER TABLE transcriptions DROP CONSTRAINT IF EXISTS transcriptions_source_check');
            
            // Add new check constraint with 'manual' included
            DB::statement("ALTER TABLE transcriptions ADD CONSTRAINT transcriptions_source_check CHECK (source IN ('generated', 'imported', 'manual'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Drop the updated constraint
            DB::statement('ALTER TABLE transcriptions DROP CONSTRAINT IF EXISTS transcriptions_source_check');
            
            // Restore original constraint (only if no 'manual' records exist)
            DB::statement("ALTER TABLE transcriptions ADD CONSTRAINT transcriptions_source_check CHECK (source IN ('generated', 'imported'))");
        }
    }
};
