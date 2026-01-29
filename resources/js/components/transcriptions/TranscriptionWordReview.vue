<script setup lang="ts">
import {
    ArrowPathIcon,
    CheckIcon,
    FunnelIcon,
    PencilIcon,
    PlusIcon,
    SpeakerWaveIcon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    TranscriptionWord,
    WordReviewConfig,
    WordReviewStats,
} from '@/types/transcriptions';

interface WordReviewData {
    words: TranscriptionWord[] | Array<Record<string, unknown>>;
    stats: WordReviewStats;
    config: WordReviewConfig;
}

interface Props {
    transcriptionId: number;
    wordReview: WordReviewData | null;
    audioPlayerRef?: {
        playRange: (start: number, end: number, padding?: number) => void;
    } | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    statsUpdated: [stats: WordReviewStats];
}>();

// State from props
const words = computed(() => (props.wordReview?.words ?? []) as TranscriptionWord[]);
const stats = computed(() => props.wordReview?.stats ?? null);
const config = computed(() => props.wordReview?.config ?? {
    playback_padding_seconds: 2,
    default_confidence_threshold: 0.7,
});

// Emit stats when they change
if (stats.value) {
    emit('statsUpdated', stats.value);
}

// Filter state
const confidenceThreshold = ref<string>('low');
const confidenceOptions = [
    { value: 'all', label: 'All words' },
    { value: 'low', label: 'Low confidence only' },
    { value: '0.5', label: '≤ 50% confidence' },
    { value: '0.6', label: '≤ 60% confidence' },
    { value: '0.7', label: '≤ 70% confidence' },
    { value: '0.8', label: '≤ 80% confidence' },
    { value: '0.9', label: '≤ 90% confidence' },
];

// Edit state
const editingWordId = ref<number | null>(null);
const editValue = ref('');
const insertAfterWordId = ref<number | null>(null);
const insertValue = ref('');

// Forms for word operations
const correctionForm = useForm({
    corrected_word: null as string | null,
    is_deleted: false,
});

const insertForm = useForm({
    word: '',
    after_word_id: null as number | null,
});

// Alignment form
const alignmentForm = useForm({
    provider: '',
    model: '',
    overwrite: false,
});

// Alignment providers state
const alignmentProviders = ref<Array<{
    id: string;
    name: string;
    models: string[];
    default_model: string | null;
    available: boolean;
    requires_credential: boolean;
    has_credential: boolean;
}>>([]);
const showAlignmentOptions = ref(false);

// Computed
const filteredWords = computed(() => {
    if (confidenceThreshold.value === 'all') {
        return words.value;
    }

    const threshold =
        confidenceThreshold.value === 'low'
            ? config.value.default_confidence_threshold
            : parseFloat(confidenceThreshold.value);

    return words.value.filter((w) => {
        if (w.is_inserted || w.corrected_word !== null || w.is_deleted) {
            return true;
        }
        return w.confidence === null || w.confidence <= threshold;
    });
});

const hasWordData = computed(() => words.value.length > 0);

// Helper to ensure number type
const ensureNumber = (value: unknown): number => {
    if (typeof value === 'number') return value;
    if (typeof value === 'string') return parseFloat(value) || 0;
    return 0;
};

const formatTime = (time: unknown): string => {
    return ensureNumber(time).toFixed(2);
};

// Play word audio snippet
const playWord = (word: TranscriptionWord) => {
    if (!props.audioPlayerRef) return;
    props.audioPlayerRef.playRange(
        ensureNumber(word.start_time),
        ensureNumber(word.end_time),
        config.value.playback_padding_seconds,
    );
};

// Start editing a word
const startEdit = (word: TranscriptionWord) => {
    editingWordId.value = word.id;
    editValue.value = word.corrected_word ?? word.word;
};

// Cancel editing
const cancelEdit = () => {
    editingWordId.value = null;
    editValue.value = '';
};

// Save word correction using Inertia
const saveCorrection = (word: TranscriptionWord) => {
    correctionForm.corrected_word = editValue.value === word.word ? null : editValue.value;
    correctionForm.is_deleted = false;
    
    correctionForm.patch(
        `/transcriptions/${props.transcriptionId}/words/${word.id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelEdit();
            },
        },
    );
};

// Delete word using Inertia
const deleteWord = (word: TranscriptionWord) => {
    if (word.is_inserted) {
        router.delete(
            `/transcriptions/${props.transcriptionId}/words/${word.id}`,
            { preserveScroll: true },
        );
    } else {
        correctionForm.is_deleted = true;
        correctionForm.corrected_word = null;
        correctionForm.patch(
            `/transcriptions/${props.transcriptionId}/words/${word.id}`,
            { preserveScroll: true },
        );
    }
};

// Restore deleted word
const restoreWord = (word: TranscriptionWord) => {
    correctionForm.is_deleted = false;
    correctionForm.corrected_word = null;
    correctionForm.patch(
        `/transcriptions/${props.transcriptionId}/words/${word.id}`,
        { preserveScroll: true },
    );
};

// Start insert mode
const startInsert = (afterWordId: number) => {
    insertAfterWordId.value = afterWordId;
    insertValue.value = '';
};

// Cancel insert
const cancelInsert = () => {
    insertAfterWordId.value = null;
    insertValue.value = '';
};

// Insert new word using Inertia
const insertWord = () => {
    if (!insertAfterWordId.value || !insertValue.value.trim()) return;

    insertForm.word = insertValue.value.trim();
    insertForm.after_word_id = insertAfterWordId.value;

    insertForm.post(
        `/transcriptions/${props.transcriptionId}/words`,
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelInsert();
            },
        },
    );
};

// Get confidence color class
const getConfidenceColor = (confidence: number | null): string => {
    if (confidence === null) return 'bg-muted text-muted-foreground';
    const conf = ensureNumber(confidence);
    if (conf >= 0.9) return 'bg-success/15 text-success';
    if (conf >= 0.7) return 'bg-primary/15 text-primary';
    if (conf >= 0.5) return 'bg-warning/15 text-warning';
    return 'bg-destructive/15 text-destructive';
};

// Get word display class
const getWordClass = (word: TranscriptionWord): string => {
    const base = 'inline-flex items-center gap-1 px-2 py-1 rounded-md text-sm font-mono cursor-pointer transition-all hover:ring-2 hover:ring-primary/50';

    if (word.is_deleted) {
        return `${base} line-through opacity-50 bg-destructive/10`;
    }
    if (word.is_inserted) {
        return `${base} bg-success/20 border border-success/30`;
    }
    if (word.corrected_word !== null) {
        return `${base} bg-primary/20 border border-primary/30`;
    }
    return `${base} ${getConfidenceColor(word.confidence)}`;
};

// Alignment functions
const fetchAlignmentProviders = async () => {
    try {
        const response = await fetch('/api/alignment/providers');
        const data = await response.json();
        alignmentProviders.value = data.providers || [];
        
        if (data.default && alignmentProviders.value.some(p => p.id === data.default)) {
            alignmentForm.provider = data.default;
            const provider = alignmentProviders.value.find(p => p.id === data.default);
            if (provider?.default_model) {
                alignmentForm.model = provider.default_model;
            }
        } else if (alignmentProviders.value.length > 0) {
            alignmentForm.provider = alignmentProviders.value[0].id;
            alignmentForm.model = alignmentProviders.value[0].default_model || '';
        }
    } catch (e) {
        console.error('Failed to fetch alignment providers:', e);
    }
};

const availableModels = computed(() => {
    const provider = alignmentProviders.value.find(p => p.id === alignmentForm.provider);
    return provider?.models || [];
});

const canAlign = computed(() => {
    const provider = alignmentProviders.value.find(p => p.id === alignmentForm.provider);
    return provider?.available ?? false;
});

const submitAlignment = (overwrite = false) => {
    alignmentForm.overwrite = overwrite;
    alignmentForm.post(`/transcriptions/${props.transcriptionId}/align`, {
        preserveScroll: true,
        onSuccess: () => {
            showAlignmentOptions.value = false;
        },
    });
};

// Load alignment providers on mount if no word data
import { onMounted } from 'vue';
onMounted(() => {
    if (!hasWordData.value) {
        fetchAlignmentProviders();
    }
});
</script>

<template>
    <div class="space-y-4">
        <!-- Header with filter -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <h3 class="text-sm font-semibold text-foreground">
                    Word-Level Review
                </h3>
                <span v-if="stats" class="text-xs text-muted-foreground">
                    ({{ stats.total_words }} words, {{ stats.correction_count }} corrections)
                </span>
            </div>

            <div class="flex items-center gap-2">
                <!-- Re-generate alignment button -->
                <DropdownMenu v-if="hasWordData">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" :disabled="alignmentForm.processing" @click="fetchAlignmentProviders">
                            <ArrowPathIcon :class="['mr-2 h-4 w-4', alignmentForm.processing ? 'animate-spin' : '']" />
                            Re-align
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-64">
                        <DropdownMenuLabel>Re-generate word alignment</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <div class="p-2 space-y-3">
                            <div class="space-y-1">
                                <label class="text-xs font-medium">Provider</label>
                                <select
                                    v-model="alignmentForm.provider"
                                    class="w-full rounded-md border border-border bg-background px-2 py-1 text-sm"
                                    @change="alignmentForm.model = alignmentProviders.find(p => p.id === alignmentForm.provider)?.default_model || ''"
                                >
                                    <option
                                        v-for="provider in alignmentProviders"
                                        :key="provider.id"
                                        :value="provider.id"
                                        :disabled="!provider.available"
                                    >
                                        {{ provider.name }}
                                    </option>
                                </select>
                            </div>
                            <div v-if="availableModels.length > 1" class="space-y-1">
                                <label class="text-xs font-medium">Model</label>
                                <select
                                    v-model="alignmentForm.model"
                                    class="w-full rounded-md border border-border bg-background px-2 py-1 text-sm"
                                >
                                    <option v-for="model in availableModels" :key="model" :value="model">
                                        {{ model }}
                                    </option>
                                </select>
                            </div>
                            <Button
                                size="sm"
                                class="w-full"
                                :disabled="alignmentForm.processing || !canAlign"
                                @click="submitAlignment(true)"
                            >
                                {{ alignmentForm.processing ? 'Starting...' : 'Overwrite & Re-align' }}
                            </Button>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>

                <!-- Filter dropdown -->
                <DropdownMenu v-if="hasWordData">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm">
                            <FunnelIcon class="mr-2 h-4 w-4" />
                            {{ confidenceOptions.find(o => o.value === confidenceThreshold)?.label || 'Filter' }}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <DropdownMenuLabel>Filter by confidence</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuRadioGroup v-model="confidenceThreshold">
                            <DropdownMenuRadioItem
                                v-for="option in confidenceOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <!-- No word data -->
        <div v-if="!hasWordData" class="rounded-lg border border-border bg-muted/50 p-8 text-center">
            <p class="text-muted-foreground">
                No word-level data available for this transcription.
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Word timing data is captured when transcribing with supported ASR providers,
                or you can generate alignment using a forced alignment model.
            </p>

            <!-- Alignment Options -->
            <div v-if="alignmentProviders.length > 0" class="mt-4 space-y-4">
                <Button
                    v-if="!showAlignmentOptions"
                    variant="outline"
                    @click="showAlignmentOptions = true; fetchAlignmentProviders()"
                >
                    <ArrowPathIcon class="mr-2 h-4 w-4" />
                    Generate Word Alignment
                </Button>

                <div v-if="showAlignmentOptions" class="mx-auto max-w-md space-y-4 rounded-lg border border-border bg-card p-4 text-left">
                    <h4 class="font-medium">Generate Word Alignment</h4>
                    <p class="text-xs text-muted-foreground">
                        Use a forced alignment model to create word-level timing data.
                    </p>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">Provider</label>
                        <select
                            v-model="alignmentForm.provider"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            @change="alignmentForm.model = alignmentProviders.find(p => p.id === alignmentForm.provider)?.default_model || ''"
                        >
                            <option
                                v-for="provider in alignmentProviders"
                                :key="provider.id"
                                :value="provider.id"
                                :disabled="!provider.available"
                            >
                                {{ provider.name }}
                                {{ provider.requires_credential && !provider.has_credential ? '(No API key)' : '' }}
                            </option>
                        </select>
                    </div>

                    <div v-if="availableModels.length > 0" class="space-y-2">
                        <label class="text-sm font-medium">Model</label>
                        <select
                            v-model="alignmentForm.model"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        >
                            <option v-for="model in availableModels" :key="model" :value="model">
                                {{ model }}
                            </option>
                        </select>
                    </div>

                    <div v-if="!canAlign" class="rounded-md bg-warning/10 p-2 text-sm text-warning">
                        No API key configured for this provider. Add your API key in Settings.
                    </div>

                    <div class="flex gap-2">
                        <Button
                            :disabled="alignmentForm.processing || !canAlign"
                            @click="submitAlignment(false)"
                        >
                            <ArrowPathIcon v-if="alignmentForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                            <ArrowPathIcon v-else class="mr-2 h-4 w-4" />
                            {{ alignmentForm.processing ? 'Starting...' : 'Start Alignment' }}
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="alignmentForm.processing"
                            @click="showAlignmentOptions = false"
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Word list -->
        <div v-else class="rounded-lg border border-border bg-card p-4">
            <div class="flex flex-wrap gap-2" dir="auto">
                <template v-for="word in filteredWords" :key="word.id">
                    <!-- Word with dropdown for actions -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                :class="getWordClass(word)"
                                :disabled="correctionForm.processing"
                                @click.middle.prevent="playWord(word)"
                            >
                                <template v-if="word.corrected_word && !word.is_deleted">
                                    <span class="line-through opacity-50">{{ word.word }}</span>
                                    <span>→</span>
                                    <span>{{ word.corrected_word }}</span>
                                </template>
                                <template v-else>
                                    {{ word.word }}
                                </template>

                                <span
                                    v-if="word.confidence !== null && !word.is_inserted"
                                    class="ml-1 text-[10px] opacity-60"
                                >
                                    {{ Math.round(ensureNumber(word.confidence) * 100) }}%
                                </span>

                                <span
                                    v-if="correctionForm.processing"
                                    class="ml-1 h-3 w-3 animate-spin rounded-full border border-current border-t-transparent"
                                />
                            </button>
                        </DropdownMenuTrigger>

                        <DropdownMenuContent class="w-64" align="start">
                            <!-- Edit mode -->
                            <div v-if="editingWordId === word.id" class="p-2 space-y-2">
                                <input
                                    v-model="editValue"
                                    type="text"
                                    class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                    dir="auto"
                                    autofocus
                                    @keyup.enter="saveCorrection(word)"
                                    @keyup.escape="cancelEdit"
                                />
                                <div class="flex gap-2">
                                    <Button
                                        size="sm"
                                        class="flex-1"
                                        :disabled="correctionForm.processing"
                                        @click="saveCorrection(word)"
                                    >
                                        <CheckIcon class="mr-1 h-4 w-4" />
                                        Save
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="cancelEdit"
                                    >
                                        <XMarkIcon class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>

                            <!-- Actions -->
                            <template v-else>
                                <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">
                                    {{ formatTime(word.start_time) }}s - {{ formatTime(word.end_time) }}s
                                    <span v-if="word.confidence !== null">
                                        • {{ Math.round(ensureNumber(word.confidence) * 100) }}% confidence
                                    </span>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                
                                <DropdownMenuItem @select="playWord(word)">
                                    <SpeakerWaveIcon class="mr-2 h-4 w-4" />
                                    Play snippet
                                </DropdownMenuItem>

                                <DropdownMenuItem
                                    v-if="!word.is_deleted"
                                    @select="startEdit(word)"
                                >
                                    <PencilIcon class="mr-2 h-4 w-4" />
                                    Edit word
                                </DropdownMenuItem>

                                <DropdownMenuItem
                                    v-if="!word.is_deleted"
                                    @select="startInsert(word.id)"
                                >
                                    <PlusIcon class="mr-2 h-4 w-4" />
                                    Insert after
                                </DropdownMenuItem>

                                <DropdownMenuSeparator />

                                <DropdownMenuItem
                                    v-if="word.is_deleted"
                                    @select="restoreWord(word)"
                                >
                                    Restore word
                                </DropdownMenuItem>

                                <DropdownMenuItem
                                    v-else
                                    class="text-destructive focus:text-destructive"
                                    @select="deleteWord(word)"
                                >
                                    <TrashIcon class="mr-2 h-4 w-4" />
                                    Delete word
                                </DropdownMenuItem>
                            </template>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <!-- Insert input -->
                    <div
                        v-if="insertAfterWordId === word.id"
                        class="flex items-center gap-1"
                    >
                        <input
                            v-model="insertValue"
                            type="text"
                            class="w-24 rounded-md border border-primary bg-background px-2 py-1 text-sm"
                            placeholder="New word"
                            dir="auto"
                            autofocus
                            @keyup.enter="insertWord"
                            @keyup.escape="cancelInsert"
                        />
                        <Button size="icon" class="h-7 w-7" :disabled="insertForm.processing" @click="insertWord">
                            <CheckIcon class="h-4 w-4" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            class="h-7 w-7"
                            @click="cancelInsert"
                        >
                            <XMarkIcon class="h-4 w-4" />
                        </Button>
                    </div>
                </template>
            </div>

            <!-- Legend -->
            <div class="mt-4 flex flex-wrap gap-4 border-t border-border pt-4 text-xs text-muted-foreground">
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded bg-success/20" />
                    Inserted
                </div>
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded bg-primary/20" />
                    Corrected
                </div>
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded bg-destructive/15" />
                    Low confidence
                </div>
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded bg-warning/15" />
                    Medium confidence
                </div>
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded bg-success/15" />
                    High confidence
                </div>
            </div>
        </div>
    </div>
</template>
