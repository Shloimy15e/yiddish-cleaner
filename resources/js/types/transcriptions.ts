export type TranscriptionType = 'base' | 'asr';
export type TranscriptionSource = 'generated' | 'imported' | 'manual' | string;
export type TranscriptionStatus =
    | 'pending'
    | 'processing'
    | 'completed'
    | 'failed'
    | string;

// Word-level transcription data
export interface TranscriptionWord {
    id: number;
    transcription_id: number;
    word_index: number;
    word: string;
    start_time: number;
    end_time: number;
    confidence: number | null;
    corrected_word: string | null;
    is_deleted: boolean;
    is_inserted: boolean;
    corrected_by: number | null;
    corrected_at: string | null;
    created_at: string;
    updated_at: string;
}

// Word review stats
export interface WordReviewStats {
    total_words: number;
    correction_count: number;
    correction_rate: number;
    inserted_count: number;
    deleted_count: number;
    low_confidence_count: number;
}

// Word review config from backend
export interface WordReviewConfig {
    playback_padding_seconds: number;
    default_confidence_threshold: number;
}

// API response for word list
export interface WordReviewResponse {
    words: TranscriptionWord[];
    stats: WordReviewStats;
    config: WordReviewConfig;
}

// ASR transcription metrics (WER/CER)
export interface AsrMetrics {
    wer: number | null;
    cer: number | null;
    substitutions: number;
    insertions: number;
    deletions: number;
    reference_words: number;
}

// WER calculation range (word indices, 0-based, inclusive)
export interface WerRange {
    wer_ref_start: number | null;
    wer_ref_end: number | null;
    wer_hyp_start: number | null;
    wer_hyp_end: number | null;
}

// Base transcription cleaning metrics
export interface CleaningMetrics {
    word_count?: number;
    char_count?: number;
    original_word_count?: number;
    original_char_count?: number;
    reduction_percent?: number;
}

// Base transcription detail (for cleaning workflow)
export interface BaseTranscription {
    id: number;
    type: 'base';
    name: string;
    audio_sample_id: number | null;
    source: TranscriptionSource;
    status: TranscriptionStatus;
    user_id?: number | null;
    user?: {
        id: number;
        name: string;
    } | null;
    
    // Text content
    text_raw: string | null;
    text_clean: string | null;
    hash_raw: string | null;
    hash_clean: string | null;
    
    // Cleaning data
    clean_rate: number | null;
    clean_rate_category: string | null;
    metrics: CleaningMetrics | null;
    removals: Record<string, unknown>[] | null;
    cleaning_preset: string | null;
    cleaning_mode: string | null;
    
    // Validation
    validated_at: string | null;
    validated_by: string | null;
    review_notes: string | null;
    
    // Training
    flagged_for_training: boolean;
    
    // Timestamps
    created_at: string;
    updated_at: string;
    
    // Relations
    audio_sample?: {
        id: number;
        name: string;
        status: string;
    } | null;
}

// ASR transcription detail (for benchmark comparison)
export interface AsrTranscription extends AsrMetrics, WerRange {
    id: number;
    type: 'asr';
    audio_sample_id: number;
    model_name: string;
    model_version: string | null;
    source: TranscriptionSource;
    status: TranscriptionStatus;
    user_id?: number | null;
    user?: {
        id: number;
        name: string;
    } | null;
    error_message?: string | null;
    hypothesis_text: string | null;
    hypothesis_hash: string | null;
    errors: Record<string, unknown> | null;
    notes: string | null;
    flagged_for_training: boolean;
    created_at: string;
    updated_at: string;
    
    // Relations
    audio_sample?: {
        id: number;
        name: string;
    };
}

// Union type for any transcription
export type Transcription = BaseTranscription | AsrTranscription;

// Legacy types for backward compatibility
export interface TranscriptionMetrics extends AsrMetrics {}

export interface TranscriptionDetail extends AsrMetrics {
    id: number;
    model_name: string;
    model_version: string | null;
    source: TranscriptionSource;
    hypothesis_text: string | null;
    notes: string | null;
    error_message?: string | null;
    created_at?: string;
}

export interface TranscriptionWithStatus extends TranscriptionDetail {
    status: TranscriptionStatus;
}

export interface BenchmarkTranscription {
    id: number;
    wer: number;
    cer: number;
    hypothesis_text: string;
    notes: string | null;
    source: string;
    created_at: string;
    audio_sample: {
        id: number;
        name: string;
    };
}

// List item for index page
export interface TranscriptionListItem {
    id: number;
    type: TranscriptionType;
    name: string | null;
    status: TranscriptionStatus;
    source: TranscriptionSource;
    audio_sample_id: number | null;
    user_id?: number | null;
    user?: {
        id: number;
        name: string;
    } | null;
    validated_at: string | null;
    clean_rate: number | null;
    created_at: string;
    audio_sample?: {
        id: number;
        name: string;
    } | null;
}
