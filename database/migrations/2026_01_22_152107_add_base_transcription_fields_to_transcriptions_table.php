<?php

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
        Schema::table('transcriptions', function (Blueprint $table) {
            // Add type field to distinguish base vs asr transcriptions
            $table->enum('type', ['base', 'asr'])->default('asr')->after('id');
            
            // Make audio_sample_id nullable (base transcriptions can be orphan)
            $table->unsignedBigInteger('audio_sample_id')->nullable()->change();
            
            // Make ASR-specific fields nullable (base transcriptions don't have these)
            $table->string('model_name')->nullable()->change();
            $table->string('model_version')->nullable()->change();
            
            // Base transcription text fields (raw = before cleaning, clean = after cleaning)
            $table->longText('text_raw')->nullable()->after('audio_sample_id');
            $table->longText('text_clean')->nullable()->after('text_raw');
            $table->string('hash_raw', 64)->nullable()->after('text_clean');
            $table->string('hash_clean', 64)->nullable()->after('hash_raw');
            
            // Cleaning metadata
            $table->unsignedTinyInteger('clean_rate')->nullable()->after('hash_clean');
            $table->string('clean_rate_category')->nullable()->after('clean_rate');
            $table->json('metrics')->nullable()->after('clean_rate_category');
            $table->json('removals')->nullable()->after('metrics');
            $table->string('cleaning_preset')->nullable()->after('removals');
            $table->string('cleaning_mode')->nullable()->after('cleaning_preset');
            
            // Validation fields (for base transcriptions)
            $table->timestamp('validated_at')->nullable()->after('notes');
            $table->string('validated_by')->nullable()->after('validated_at');
            $table->text('review_notes')->nullable()->after('validated_by');
            
            // Name field for base transcriptions
            $table->string('name')->nullable()->after('type');
            
            // Add indexes
            $table->index('type');
            $table->index('hash_raw');
            $table->index('hash_clean');
        });

        // Add check constraint: asr type requires audio_sample_id
        // Note: MySQL doesn't enforce CHECK constraints before 8.0.16, so we'll handle this in model validation
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transcriptions ADD CONSTRAINT transcriptions_asr_requires_audio_sample CHECK (type != \'asr\' OR audio_sample_id IS NOT NULL)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove check constraint if exists
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transcriptions DROP CONSTRAINT IF EXISTS transcriptions_asr_requires_audio_sample');
        }

        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['hash_raw']);
            $table->dropIndex(['hash_clean']);
            
            $table->dropColumn([
                'type',
                'name',
                'text_raw',
                'text_clean',
                'hash_raw',
                'hash_clean',
                'clean_rate',
                'clean_rate_category',
                'metrics',
                'removals',
                'cleaning_preset',
                'cleaning_mode',
                'validated_at',
                'validated_by',
                'review_notes',
            ]);
            
            // Make audio_sample_id required again
            $table->unsignedBigInteger('audio_sample_id')->nullable(false)->change();
        });
    }
};
