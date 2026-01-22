export type TranscriptionSource = 'generated' | 'imported' | string;
export type TranscriptionStatus =
    | 'pending'
    | 'processing'
    | 'completed'
    | 'failed'
    | string;

export interface TranscriptionMetrics {
    wer: number | null;
    cer: number | null;
    substitutions: number;
    insertions: number;
    deletions: number;
    reference_words: number;
}

export interface TranscriptionDetail extends TranscriptionMetrics {
    id: number;
    model_name: string;
    model_version: string | null;
    source: TranscriptionSource;
    hypothesis_text: string | null;
    notes: string | null;
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
