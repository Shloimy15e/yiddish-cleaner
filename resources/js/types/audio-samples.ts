import type { AsrTranscription, BaseTranscription, TranscriptionWithStatus } from './transcriptions';

// New status values
export type AudioSampleStatus =
    | 'draft'
    | 'pending_base'
    | 'unclean'
    | 'ready'
    | 'benchmarked'
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
    source_url: string | null;
    audio_duration_seconds: number | null;
    status: AudioSampleStatus;
    error_message: string | null;
    created_at: string;
    updated_at: string;
    processing_run: AudioSampleProcessingRun | null;
    
    // Base transcription (reference/ground truth)
    base_transcription: BaseTranscription | null;
    
    // ASR transcriptions (hypothesis/benchmark results)
    asr_transcriptions?: AsrTranscription[];
}

export interface AudioSampleListItem {
    id: number;
    name: string;
    status: AudioSampleStatus;
    audio_duration_seconds: number | null;
    created_at: string;
    processing_run: AudioSampleProcessingRunSummary | null;
    
    // Base transcription summary
    base_transcription?: {
        id: number;
        name: string;
        status: string;
        clean_rate: number | null;
        validated_at: string | null;
    } | null;
}

export interface AudioSampleSummary {
    id: number;
    name: string;
    status: AudioSampleStatus;
    created_at: string;
    base_transcription?: {
        id: number;
        clean_rate: number | null;
        validated_at: string | null;
    } | null;
}

export interface AudioSampleRunItem {
    id: number;
    name: string;
    status: AudioSampleStatus;
    error_message: string | null;
    created_at: string;
    base_transcription?: {
        clean_rate: number | null;
    } | null;
}

export interface AudioSampleContext {
    id: number;
    name: string;
    status: AudioSampleStatus;
    created_at: string;
    base_transcription: BaseTranscription | null;
    processing_run: {
        preset: string;
        mode: string;
    } | null;
}

export interface AudioSampleReference {
    id: number;
    name: string;
    base_transcription?: {
        text_clean: string | null;
    } | null;
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
