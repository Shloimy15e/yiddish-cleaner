import type { AudioSampleRunItem } from './audio-samples';

export interface ProcessingRunListItem {
    id: number;
    status: string;
    source_type: string | null;
    source_url: string | null;
    total: number;
    completed: number;
    failed: number;
    created_at: string;
}

export interface ProcessingRunDetail {
    id: number;
    preset: string | null;
    mode: string | null;
    source_type: string | null;
    source_url: string | null;
    status: string;
    total: number;
    completed: number;
    failed: number;
    error_message: string | null;
    created_at: string;
    options: Record<string, unknown> | null;
    audio_samples: AudioSampleRunItem[];
}
