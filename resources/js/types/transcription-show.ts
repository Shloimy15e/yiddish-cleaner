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

export type DiffSegment = {
    type: 'same' | 'removed' | 'added';
    text: string;
};

export type AlignmentItem = {
    type: 'correct' | 'sub' | 'ins' | 'del';
    ref: string | null;
    hyp: string | null;
};
