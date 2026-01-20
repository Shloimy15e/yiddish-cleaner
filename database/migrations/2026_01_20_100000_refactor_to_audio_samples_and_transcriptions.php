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
        // Helper to check if a foreign key exists
        $hasForeignKey = fn (string $table, string $fkName) => collect(Schema::getForeignKeys($table))
            ->pluck('name')
            ->contains($fkName);

        // Helper to check if an index exists
        $hasIndex = fn (string $table, string $indexName) => collect(Schema::getIndexes($table))
            ->pluck('name')
            ->contains($indexName);

        // Helper to check if a column exists
        $hasColumn = fn (string $table, string $column) => Schema::hasColumn($table, $column);

        // ============================================
        // Step 1: Handle documents -> audio_samples
        // ============================================
        if (Schema::hasTable('documents') && !Schema::hasTable('audio_samples')) {
            Schema::rename('documents', 'audio_samples');
        }

        // Rename columns in audio_samples (if old names exist)
        Schema::table('audio_samples', function (Blueprint $table) use ($hasColumn, $hasIndex) {
            if ($hasColumn('audio_samples', 'original_text')) {
                $table->renameColumn('original_text', 'reference_text_raw');
            }
            if ($hasColumn('audio_samples', 'cleaned_text')) {
                $table->renameColumn('cleaned_text', 'reference_text_clean');
            }
            if ($hasColumn('audio_samples', 'original_hash')) {
                $table->renameColumn('original_hash', 'reference_hash_raw');
            }
            if ($hasColumn('audio_samples', 'cleaned_hash')) {
                $table->renameColumn('cleaned_hash', 'reference_hash_clean');
            }
            if ($hasColumn('audio_samples', 'audio_length')) {
                $table->renameColumn('audio_length', 'audio_duration_seconds');
            }
            if ($hasColumn('audio_samples', 'audio_link')) {
                $table->dropColumn('audio_link');
            }
        });

        // Update indexes in audio_samples
        Schema::table('audio_samples', function (Blueprint $table) use ($hasIndex) {
            if ($hasIndex('audio_samples', 'documents_original_hash_index')) {
                $table->dropIndex('documents_original_hash_index');
            }
            if (!$hasIndex('audio_samples', 'audio_samples_reference_hash_raw_index')) {
                $table->index('reference_hash_raw');
            }
        });

        // ============================================
        // Step 2: Handle benchmark_results -> transcriptions  
        // ============================================
        
        // Drop FK before renaming if exists
        if (Schema::hasTable('benchmark_results')) {
            if ($hasForeignKey('benchmark_results', 'benchmark_results_document_id_foreign')) {
                Schema::table('benchmark_results', function (Blueprint $table) {
                    $table->dropForeign(['document_id']);
                });
            }
            Schema::rename('benchmark_results', 'transcriptions');
        }

        // Rename columns in transcriptions (if old names exist)
        if (Schema::hasTable('transcriptions')) {
            Schema::table('transcriptions', function (Blueprint $table) use ($hasColumn, $hasIndex) {
                if ($hasColumn('transcriptions', 'document_id')) {
                    $table->renameColumn('document_id', 'audio_sample_id');
                }
                if ($hasColumn('transcriptions', 'transcribed_text')) {
                    $table->renameColumn('transcribed_text', 'hypothesis_text');
                }
                if ($hasColumn('transcriptions', 'transcribed_hash')) {
                    $table->renameColumn('transcribed_hash', 'hypothesis_hash');
                }
                if (!$hasColumn('transcriptions', 'source')) {
                    $table->enum('source', ['generated', 'imported'])->default('generated')->after('hypothesis_hash');
                }
            });

            // Update indexes in transcriptions
            Schema::table('transcriptions', function (Blueprint $table) use ($hasIndex, $hasForeignKey) {
                if ($hasIndex('transcriptions', 'benchmark_results_document_id_model_name_index')) {
                    $table->dropIndex('benchmark_results_document_id_model_name_index');
                }
                if (!$hasIndex('transcriptions', 'transcriptions_audio_sample_id_model_name_index')) {
                    $table->index(['audio_sample_id', 'model_name']);
                }
                if (!$hasForeignKey('transcriptions', 'transcriptions_audio_sample_id_foreign')) {
                    $table->foreign('audio_sample_id')->references('id')->on('audio_samples')->cascadeOnDelete();
                }
            });
        }

        // ============================================
        // Step 3: Handle training_document -> audio_sample_training_version
        // ============================================
        
        // Drop FK before renaming if exists
        if (Schema::hasTable('training_document')) {
            if ($hasForeignKey('training_document', 'training_document_document_id_foreign')) {
                Schema::table('training_document', function (Blueprint $table) {
                    $table->dropForeign(['document_id']);
                });
            }
            Schema::rename('training_document', 'audio_sample_training_version');
        }

        // Update pivot table
        if (Schema::hasTable('audio_sample_training_version')) {
            // First rename the column if needed
            Schema::table('audio_sample_training_version', function (Blueprint $table) use ($hasColumn) {
                if ($hasColumn('audio_sample_training_version', 'document_id')) {
                    $table->renameColumn('document_id', 'audio_sample_id');
                }
            });

            // Drop old FK on training_version_id that depends on the unique index
            Schema::table('audio_sample_training_version', function (Blueprint $table) use ($hasForeignKey) {
                if ($hasForeignKey('audio_sample_training_version', 'training_document_training_version_id_foreign')) {
                    $table->dropForeign('training_document_training_version_id_foreign');
                }
            });

            // Now we can safely modify indexes
            Schema::table('audio_sample_training_version', function (Blueprint $table) use ($hasIndex) {
                if ($hasIndex('audio_sample_training_version', 'training_document_training_version_id_document_id_unique')) {
                    $table->dropUnique('training_document_training_version_id_document_id_unique');
                }
                if (!$hasIndex('audio_sample_training_version', 'astv_training_version_audio_sample_unique')) {
                    $table->unique(['training_version_id', 'audio_sample_id'], 'astv_training_version_audio_sample_unique');
                }
            });

            // Re-add foreign keys with new names
            Schema::table('audio_sample_training_version', function (Blueprint $table) use ($hasForeignKey) {
                if (!$hasForeignKey('audio_sample_training_version', 'audio_sample_training_version_training_version_id_foreign')) {
                    $table->foreign('training_version_id')->references('id')->on('training_versions')->cascadeOnDelete();
                }
                if (!$hasForeignKey('audio_sample_training_version', 'audio_sample_training_version_audio_sample_id_foreign')) {
                    $table->foreign('audio_sample_id')->references('id')->on('audio_samples')->cascadeOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key constraints before reverting
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropForeign(['audio_sample_id']);
        });

        Schema::table('audio_sample_training_version', function (Blueprint $table) {
            $table->dropForeign(['audio_sample_id']);
        });

        // Revert pivot table
        Schema::table('audio_sample_training_version', function (Blueprint $table) {
            $table->dropUnique(['training_version_id', 'audio_sample_id']);
        });

        Schema::table('audio_sample_training_version', function (Blueprint $table) {
            $table->renameColumn('audio_sample_id', 'document_id');
        });

        Schema::table('audio_sample_training_version', function (Blueprint $table) {
            $table->unique(['training_version_id', 'document_id']);
        });

        Schema::rename('audio_sample_training_version', 'training_document');

        // Revert transcriptions table
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropIndex(['audio_sample_id', 'model_name']);
        });

        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn('source');
            $table->renameColumn('hypothesis_hash', 'transcribed_hash');
            $table->renameColumn('hypothesis_text', 'transcribed_text');
            $table->renameColumn('audio_sample_id', 'document_id');
        });

        Schema::rename('transcriptions', 'benchmark_results');

        Schema::table('benchmark_results', function (Blueprint $table) {
            $table->index(['document_id', 'model_name']);
        });

        // Revert audio_samples table
        Schema::table('audio_samples', function (Blueprint $table) {
            $table->dropIndex(['reference_hash_raw']);
        });

        Schema::table('audio_samples', function (Blueprint $table) {
            $table->text('audio_link')->nullable()->after('reference_text_clean');
            $table->renameColumn('audio_duration_seconds', 'audio_length');
            $table->renameColumn('reference_hash_clean', 'cleaned_hash');
            $table->renameColumn('reference_hash_raw', 'original_hash');
            $table->renameColumn('reference_text_clean', 'cleaned_text');
            $table->renameColumn('reference_text_raw', 'original_text');
        });

        Schema::table('audio_samples', function (Blueprint $table) {
            $table->index('original_hash');
        });

        Schema::rename('audio_samples', 'documents');

        // Add foreign key constraints back
        Schema::table('benchmark_results', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
        });

        Schema::table('training_document', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
        });
    }
};
