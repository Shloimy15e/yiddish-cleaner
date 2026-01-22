import type { TranscriptionWithStatus } from './transcriptions';

export type AudioSampleStatus =
    | 'pending_transcript'
    | 'imported'
    | 'cleaning'
    | 'cleaned'
    | 'validated'
    | 'failed'
    | string;

export interface AudioMedia {
    url: string;
    name: string;
    size: number;
    mime_type: string;
}

export interface AudioSampleProcessingRun {
    id: number;
    preset: string;
    mode: string;
}

export interface AudioSampleProcessingRunSummary {
    preset: string;
    mode: 'rule' | 'llm' | string;
    llm_provider: string | null;
    llm_model: string | null;
}

export interface AudioSampleDetail {
    id: number;
    name: string;
    reference_text_raw: string;
    reference_text_clean: string;
    status: AudioSampleStatus;
    error_message: string | null;
    clean_rate: number | null;
    clean_rate_category: string | null;
    metrics: Record<string, number> | null;
    removals: Array<{ type: string; original: string; count: number }> | null;
    validated_at: string | null;
    created_at: string;
    processing_run: AudioSampleProcessingRun | null;
    transcriptions?: TranscriptionWithStatus[];
}

export interface AudioSampleListItem {
    id: number;
    name: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    status: AudioSampleStatus;
    validated_at: string | null;
    created_at: string;
    processing_run: AudioSampleProcessingRunSummary | null;
}

export interface AudioSampleSummary {
    id: number;
    name: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    status: AudioSampleStatus;
    created_at: string;
}

export interface AudioSampleRunItem {
    id: number;
    name: string;
    status: AudioSampleStatus;
    clean_rate: number | null;
    error_message: string | null;
    created_at: string;
}

export interface AudioSampleContext {
    id: number;
    name: string;
    status: AudioSampleStatus;
    created_at: string;
    reference_text_raw: string;
    reference_text_clean: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    processing_run: {
        preset: string;
        mode: string;
    } | null;
}

export interface AudioSampleReference {
    id: number;
    name: string;
    reference_text_clean: string | null;
}

export interface Preset {
    name: string;
    description: string;
    processors: string[];
}

export interface LlmModel {
    id: string;
    name: string;
    context_length?: number;
}

export interface LlmProvider {
    name: string;
    default_model: string;
    has_credential: boolean;
    models: LlmModel[];
}

export interface AsrProvider {
    name: string;
    default_model: string;
    has_credential: boolean;
    models: { id: string; name: string }[];
    async: boolean;
    description: string;
}

export interface TranscriptionSummary extends TranscriptionWithStatus {}
