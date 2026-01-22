export interface TrainingDocument {
    id: number;
    name: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    validated_at?: string | null;
}

export interface TrainingVersionBase {
    id: number;
    version: string;
    name: string;
    document_count: number;
    is_active: boolean;
    created_at: string;
    criteria: Record<string, unknown> | null;
}

export interface TrainingVersionDetail extends TrainingVersionBase {
    documents: TrainingDocument[];
}

export interface PaginatedTrainingVersion {
    data: TrainingVersionBase[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
