<script setup lang="ts">
import {
    ArrowPathIcon,
    CheckIcon,
    ExclamationTriangleIcon,
    FunnelIcon,
    PencilIcon,
    PlusIcon,
    SpeakerWaveIcon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { buildAlignmentFromDiff } from '@/lib/transcriptionUtils';
import type { AlignmentItem } from '@/types/transcription-show';
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
    referenceText: string | null;
    hypothesisText: string | null;
    audioPlayerRef?: {
        playRange: (start: number, end: number, padding?: number) => void;
    } | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    statsUpdated: [stats: WordReviewStats];
}>();

// State from props
const words = computed(
    () => (props.wordReview?.words ?? []) as TranscriptionWord[],
);
const stats = computed(() => props.wordReview?.stats ?? null);
const config = computed(
    () =>
        props.wordReview?.config ?? {
            playback_padding_seconds: 2,
            default_confidence_threshold: 0.7,
        },
);

// Emit stats when they change
if (stats.value) {
    emit('statsUpdated', stats.value);
}

// Computed mode
const hasWordData = computed(() => words.value.length > 0);
const hasReferenceText = computed(() => !!props.referenceText?.trim());
const hasHypothesisText = computed(() => !!props.hypothesisText?.trim());

// View mode: 'alignment' (with ref), 'words' (no ref, has words), 'text' (no words)
const viewMode = computed(() => {
    if (hasReferenceText.value && hasHypothesisText.value) {
        return 'alignment';
    }
    if (hasWordData.value) {
        return 'words';
    }
    return 'text';
});

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

// Alignment display mode: 'line-by-line' or 'panels'
const alignmentDisplayMode = ref<'line-by-line' | 'panels'>('line-by-line');

// Auto-focus directive for dynamically rendered inputs
const vFocus = { mounted: (el: HTMLElement) => el.focus() };

// Edit state
const editingWordId = ref<number | null>(null);
const editValue = ref('');
const insertAfterWordId = ref<number | null>(null);
const insertValue = ref('');
const savingWordId = ref<number | null>(null);

// Multi-select state
const isSelectionMode = ref(false);
const selectedWordIds = ref<Set<number>>(new Set());
const isBulkProcessing = ref(false);

// Text editing state (for when no word data)
const isEditingText = ref(false);
const editedText = ref('');

// Shared dropdown state — single dropdown for all words
const activeWord = ref<TranscriptionWord | null>(null);
const activeWordTriggerEl = ref<HTMLElement | null>(null);
const actionBarRef = ref<HTMLElement | null>(null);

// Forms
const correctionForm = useForm({
    corrected_word: null as string | null,
    is_deleted: false,
    is_critical_error: false,
});

const insertForm = useForm({
    word: '',
    after_word_id: null as number | null,
});

// Alignment
const alignment = computed(() => {
    if (!hasReferenceText.value || !hasHypothesisText.value) return [];
    return buildAlignmentFromDiff(props.referenceText!, props.hypothesisText!);
});

// Map hypothesis words to word review data by matching normalized tokens
const wordsByToken = computed(() => {
    const map = new Map<number, TranscriptionWord>();

    // Create a position-based mapping
    let wordIdx = 0;
    for (const word of words.value) {
        if (word.is_inserted) continue; // Skip inserted words for alignment mapping
        map.set(wordIdx, word);
        wordIdx++;
    }
    return map;
});

// Build alignment with word review data attached
const alignmentWithWords = computed(() => {
    if (!alignment.value.length) return [];

    let hypWordIndex = 0;
    return alignment.value.map((item, alignIdx) => {
        let wordData: TranscriptionWord | null = null;

        // For items that have hypothesis words (correct, sub, ins)
        if (item.type !== 'del' && item.hyp !== null) {
            wordData = wordsByToken.value.get(hypWordIndex) || null;
            hypWordIndex++;
        }

        return {
            ...item,
            alignIdx,
            wordData,
        };
    });
});

// Get inserted words (words added by user, not from original ASR)
const insertedWords = computed(() => words.value.filter((w) => w.is_inserted));

// Filtered alignment items based on confidence
const filteredAlignmentWithWords = computed(() => {
    if (confidenceThreshold.value === 'all') {
        return alignmentWithWords.value;
    }

    const threshold =
        confidenceThreshold.value === 'low'
            ? config.value.default_confidence_threshold
            : parseFloat(confidenceThreshold.value);

    return alignmentWithWords.value.filter((item) => {
        // Always show items without word data (ref-only items)
        if (!item.wordData) return true;

        const w = item.wordData;
        // Always show corrected/flagged items
        if (w.corrected_word !== null || w.is_deleted || w.is_critical_error) {
            return true;
        }
        // Filter by confidence
        return w.confidence === null || w.confidence <= threshold;
    });
});

// Responsive window width for mobile layout
const windowWidth = ref(window.innerWidth);
let resizeTimer: ReturnType<typeof setTimeout> | null = null;
const onResize = () => {
    if (resizeTimer) clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        windowWidth.value = window.innerWidth;
    }, 200);
};

// Responsive perRow for alignment line-by-line display
const perRow = computed(() => {
    if (windowWidth.value < 640) return 6;
    if (windowWidth.value < 1024) return 10;
    return 15;
});

// Chunked alignment for line-by-line display
const chunkedAlignment = computed(() => {
    const chunks: Array<typeof alignmentWithWords.value> = [];
    const items = alignmentWithWords.value; // Use all items for line-by-line, not filtered
    for (let i = 0; i < items.length; i += perRow.value) {
        chunks.push(items.slice(i, i + perRow.value));
    }
    return chunks;
});

// Word-only view filtering
const filteredWords = computed(() => {
    if (confidenceThreshold.value === 'all') {
        return words.value;
    }

    const threshold =
        confidenceThreshold.value === 'low'
            ? config.value.default_confidence_threshold
            : parseFloat(confidenceThreshold.value);

    return words.value.filter((w) => {
        if (
            w.is_inserted ||
            w.corrected_word !== null ||
            w.is_deleted ||
            w.is_critical_error
        ) {
            return true;
        }
        return w.confidence === null || w.confidence <= threshold;
    });
});

// Chunked rendering for virtualization (word-only view)
const CHUNK_SIZE = 100;
const visibleChunks = ref<Set<number>>(new Set([0, 1]));

let chunkObserver: IntersectionObserver | null = null;

const setupChunkObserver = () => {
    chunkObserver = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                const index = Number(
                    (entry.target as HTMLElement).dataset.chunkIndex,
                );
                if (entry.isIntersecting) {
                    visibleChunks.value.add(index);
                    visibleChunks.value.add(index - 1);
                    visibleChunks.value.add(index + 1);
                }
            }
            visibleChunks.value = new Set(visibleChunks.value);
        },
        { rootMargin: '200px' },
    );
};

const observeChunk = (index: number, el: HTMLElement | null) => {
    if (el && chunkObserver) {
        chunkObserver.observe(el);
    }
};

const chunkedWords = computed(() => {
    const chunks: TranscriptionWord[][] = [];
    const items = filteredWords.value;
    for (let i = 0; i < items.length; i += CHUNK_SIZE) {
        chunks.push(items.slice(i, i + CHUNK_SIZE));
    }
    return chunks;
});

// Helpers
const ensureNumber = (value: unknown): number => {
    if (typeof value === 'number') return value;
    if (typeof value === 'string') return parseFloat(value) || 0;
    return 0;
};

// Open action bar for a word
const openWordMenu = (word: TranscriptionWord, event: MouseEvent) => {
    activeWord.value = word;
    activeWordTriggerEl.value = event.currentTarget as HTMLElement;
};

const closeWordMenu = () => {
    activeWord.value = null;
    activeWordTriggerEl.value = null;
};

const doWordAction = (action: (word: TranscriptionWord) => void) => {
    const word = activeWord.value;
    if (!word) return;
    closeWordMenu();
    action(word);
};

const onClickOutside = (event: MouseEvent) => {
    if (!activeWord.value) return;
    const bar = actionBarRef.value;
    if (bar && !bar.contains(event.target as Node)) {
        closeWordMenu();
    }
};

const onKeydown = (event: KeyboardEvent) => {
    // Don't intercept when typing in an input
    const tag = (event.target as HTMLElement)?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;

    // Escape closes the action bar
    if (event.key === 'Escape') {
        if (activeWord.value) {
            closeWordMenu();
            event.preventDefault();
        }
        return;
    }

    // Shortcuts only active when action bar is open
    if (!activeWord.value) return;

    const word = activeWord.value;
    switch (event.key.toLowerCase()) {
        case 'e':
            if (!word.is_deleted) doWordAction(startEdit);
            break;
        case 'i':
            if (!word.is_deleted) doWordAction((w) => startInsert(w.id));
            break;
        case 'p':
            doWordAction(playWord);
            break;
        case 'c':
            if (!word.is_deleted && !word.is_inserted) doWordAction(toggleCriticalError);
            break;
        case 'd':
        case 'delete':
            if (word.is_deleted) doWordAction(restoreWord);
            else doWordAction(deleteWord);
            break;
        default:
            return;
    }
    event.preventDefault();
};

const actionBarStyle = computed(() => {
    if (!activeWordTriggerEl.value) return { display: 'none' };
    const rect = activeWordTriggerEl.value.getBoundingClientRect();
    return {
        top: rect.bottom + 4 + 'px',
        left: rect.left + 'px',
    };
});

// Actions
const playWord = (word: TranscriptionWord) => {
    if (!props.audioPlayerRef) return;
    props.audioPlayerRef.playRange(
        ensureNumber(word.start_time),
        ensureNumber(word.end_time),
        config.value.playback_padding_seconds,
    );
};

const startEdit = (word: TranscriptionWord) => {
    editingWordId.value = word.id;
    editValue.value = word.corrected_word ?? word.word;
};

const cancelEdit = () => {
    editingWordId.value = null;
    editValue.value = '';
};

const saveCorrection = (word: TranscriptionWord) => {
    savingWordId.value = word.id;
    correctionForm.corrected_word =
        editValue.value === word.word ? null : editValue.value;
    correctionForm.is_deleted = false;
    correctionForm.is_critical_error = false;

    correctionForm.patch(
        route('transcriptions.words.update', { transcription: props.transcriptionId, word: word.id }),
        {
            preserveScroll: true,
            onSuccess: () => cancelEdit(),
            onFinish: () => {
                savingWordId.value = null;
            },
        },
    );
};

const deleteWord = (word: TranscriptionWord) => {
    savingWordId.value = word.id;

    if (word.is_inserted) {
        router.delete(
            route('transcriptions.words.destroy', { transcription: props.transcriptionId, word: word.id }),
            {
                preserveScroll: true,
                onFinish: () => {
                    savingWordId.value = null;
                },
            },
        );
    } else {
        correctionForm.is_deleted = true;
        correctionForm.corrected_word = null;
        correctionForm.is_critical_error = false;
        correctionForm.patch(
            route('transcriptions.words.update', { transcription: props.transcriptionId, word: word.id }),
            {
                preserveScroll: true,
                onFinish: () => {
                    savingWordId.value = null;
                },
            },
        );
    }
};

const restoreWord = (word: TranscriptionWord) => {
    savingWordId.value = word.id;
    correctionForm.is_deleted = false;
    correctionForm.corrected_word = null;
    correctionForm.is_critical_error = false;
    correctionForm.patch(
        route('transcriptions.words.update', { transcription: props.transcriptionId, word: word.id }),
        {
            preserveScroll: true,
            onFinish: () => {
                savingWordId.value = null;
            },
        },
    );
};

const toggleCriticalError = (word: TranscriptionWord) => {
    savingWordId.value = word.id;

    // Use router.patch directly with only the critical error field
    router.patch(
        route('transcriptions.words.update', { transcription: props.transcriptionId, word: word.id }),
        { is_critical_error: !word.is_critical_error },
        {
            preserveScroll: true,
            onFinish: () => {
                savingWordId.value = null;
            },
        },
    );
};

// Multi-select functions
const toggleSelectionMode = () => {
    isSelectionMode.value = !isSelectionMode.value;
    if (!isSelectionMode.value) {
        selectedWordIds.value.clear();
    }
};

const toggleWordSelection = (wordId: number) => {
    if (selectedWordIds.value.has(wordId)) {
        selectedWordIds.value.delete(wordId);
    } else {
        selectedWordIds.value.add(wordId);
    }
    // Force reactivity
    selectedWordIds.value = new Set(selectedWordIds.value);
};

const isWordSelected = (wordId: number): boolean => {
    return selectedWordIds.value.has(wordId);
};

const clearSelection = () => {
    selectedWordIds.value.clear();
    selectedWordIds.value = new Set();
};

const bulkDelete = () => {
    if (selectedWordIds.value.size === 0) return;

    isBulkProcessing.value = true;
    router.post(
        route('transcriptions.words.bulk', { transcription: props.transcriptionId }),
        {
            word_ids: Array.from(selectedWordIds.value),
            action: 'delete',
        },
        {
            preserveScroll: true,
            onFinish: () => {
                isBulkProcessing.value = false;
                selectedWordIds.value.clear();
                selectedWordIds.value = new Set();
            },
        },
    );
};

const bulkMarkCriticalError = () => {
    if (selectedWordIds.value.size === 0) return;

    isBulkProcessing.value = true;
    router.post(
        route('transcriptions.words.bulk', { transcription: props.transcriptionId }),
        {
            word_ids: Array.from(selectedWordIds.value),
            action: 'mark_critical_error',
        },
        {
            preserveScroll: true,
            onFinish: () => {
                isBulkProcessing.value = false;
                selectedWordIds.value.clear();
                selectedWordIds.value = new Set();
            },
        },
    );
};

const startInsert = (afterWordId: number) => {
    insertAfterWordId.value = afterWordId;
    insertValue.value = '';
};

const cancelInsert = () => {
    insertAfterWordId.value = null;
    insertValue.value = '';
};

const insertWord = () => {
    if (!insertAfterWordId.value || !insertValue.value.trim()) return;

    insertForm.word = insertValue.value.trim();
    insertForm.after_word_id = insertAfterWordId.value;

    insertForm.post(route('transcriptions.words.store', { transcription: props.transcriptionId }), {
        preserveScroll: true,
        onSuccess: () => cancelInsert(),
    });
};

// Styling helpers
const getConfidenceColor = (confidence: number | null): string => {
    if (confidence === null) return 'bg-muted text-muted-foreground';
    const conf = ensureNumber(confidence);
    if (conf >= 0.9) return 'bg-success/15 text-success';
    if (conf >= 0.7) return 'bg-primary/15 text-primary';
    if (conf >= 0.5) return 'bg-warning/15 text-warning';
    return 'bg-destructive/15 text-destructive';
};

const getWordClass = (word: TranscriptionWord): string => {
    const base =
        'inline-flex items-center gap-1 px-2 py-1 rounded-md text-sm cursor-pointer transition-shadow hover:ring-2 hover:ring-primary/50';
    const selected = isWordSelected(word.id)
        ? 'ring-2 ring-primary ring-offset-1'
        : '';

    if (word.is_deleted) {
        return `${base} ${selected} line-through opacity-50 bg-destructive/10`;
    }
    if (word.is_critical_error) {
        return `${base} ${selected} bg-orange-500/20 border-2 border-orange-500/50 ring-1 ring-orange-500/30`;
    }
    if (word.is_inserted) {
        return `${base} ${selected} bg-success/20 border border-success/30`;
    }
    if (word.corrected_word !== null) {
        return `${base} ${selected} bg-primary/20 border border-primary/30`;
    }
    return `${base} ${selected} ${getConfidenceColor(word.confidence)}`;
};

const getAlignmentItemClass = (
    item: AlignmentItem & { wordData?: TranscriptionWord | null },
): string => {
    const baseRef = 'rounded px-1.5 py-0.5 text-sm';
    const baseHyp =
        'inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-sm cursor-pointer transition-shadow hover:ring-2 hover:ring-primary/50';
    const selected =
        item.wordData && isWordSelected(item.wordData.id)
            ? 'ring-2 ring-primary ring-offset-1'
            : '';

    // If this is a ref-only display
    if (item.type === 'del') {
        return `${baseRef} bg-rose-200 dark:bg-rose-900/50 line-through`;
    }

    // If there's word data, style based on word state
    if (item.wordData) {
        const w = item.wordData;
        if (w.is_deleted) {
            return `${baseHyp} ${selected} line-through opacity-50 bg-destructive/10`;
        }
        if (w.is_critical_error) {
            return `${baseHyp} ${selected} bg-orange-500/20 border-2 border-orange-500/50`;
        }
        if (w.corrected_word !== null) {
            return `${baseHyp} ${selected} bg-primary/20 border border-primary/30`;
        }
    }

    // Default alignment styling with selection
    if (item.type === 'correct') {
        return `${baseHyp} ${selected} bg-muted`;
    }
    if (item.type === 'sub') {
        return `${baseHyp} ${selected} bg-amber-200 dark:bg-amber-900/50`;
    }
    if (item.type === 'ins') {
        return `${baseHyp} ${selected} bg-emerald-200 dark:bg-emerald-900/50`;
    }

    return `${baseHyp} ${selected}`;
};

// Alignment providers (for generating alignment if no word data)
const alignmentProviders = ref<
    Array<{
        id: string;
        name: string;
        models: string[];
        default_model: string | null;
        available: boolean;
        requires_credential: boolean;
        has_credential: boolean;
    }>
>([]);
const showAlignmentOptions = ref(false);
const alignmentForm = useForm({
    provider: '',
    model: '',
    overwrite: false,
});

const fetchAlignmentProviders = async () => {
    try {
        const response = await fetch(route('api.alignment.providers'));
        const data = await response.json();
        alignmentProviders.value = data.providers || [];

        if (
            data.default &&
            alignmentProviders.value.some((p) => p.id === data.default)
        ) {
            alignmentForm.provider = data.default;
            const provider = alignmentProviders.value.find(
                (p) => p.id === data.default,
            );
            if (provider?.default_model) {
                alignmentForm.model = provider.default_model;
            }
        } else if (alignmentProviders.value.length > 0) {
            alignmentForm.provider = alignmentProviders.value[0].id;
            alignmentForm.model =
                alignmentProviders.value[0].default_model || '';
        }
    } catch (e) {
        console.error('Failed to fetch alignment providers:', e);
    }
};

const availableModels = computed(() => {
    const provider = alignmentProviders.value.find(
        (p) => p.id === alignmentForm.provider,
    );
    return provider?.models || [];
});

const canAlign = computed(() => {
    const provider = alignmentProviders.value.find(
        (p) => p.id === alignmentForm.provider,
    );
    return provider?.available ?? false;
});

const submitAlignment = (overwrite = false) => {
    alignmentForm.overwrite = overwrite;
    alignmentForm.post(route('transcriptions.align', { transcription: props.transcriptionId }), {
        preserveScroll: true,
        onSuccess: () => {
            showAlignmentOptions.value = false;
        },
    });
};

onMounted(() => {
    if (!hasWordData.value) {
        fetchAlignmentProviders();
    }
    setupChunkObserver();
    window.addEventListener('resize', onResize);
    window.addEventListener('pointerdown', onClickOutside);
    window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    if (chunkObserver) {
        chunkObserver.disconnect();
        chunkObserver = null;
    }
    window.removeEventListener('resize', onResize);
    window.removeEventListener('pointerdown', onClickOutside);
    window.removeEventListener('keydown', onKeydown);
    if (resizeTimer) clearTimeout(resizeTimer);
});
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-foreground">
                    {{
                        viewMode === 'alignment'
                            ? 'Alignment Review'
                            : viewMode === 'words'
                              ? 'Word Review'
                              : 'Text Review'
                    }}
                </h3>
                <span v-if="stats" class="text-xs text-muted-foreground">
                    ({{ stats.total_words }} words,
                    {{ stats.correction_count }} corrections)
                </span>
                <!-- Selection mode indicator -->
                <span
                    v-if="isSelectionMode && selectedWordIds.size > 0"
                    class="rounded-full bg-primary/20 px-2 py-0.5 text-xs font-medium text-primary"
                >
                    {{ selectedWordIds.size }} selected
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <!-- Bulk actions (when in selection mode with selections) -->
                <template v-if="isSelectionMode && selectedWordIds.size > 0">
                    <Button
                        variant="outline"
                        size="sm"
                        class="text-orange-500 hover:text-orange-600"
                        :disabled="isBulkProcessing"
                        @click="bulkMarkCriticalError"
                    >
                        <ExclamationTriangleIcon class="mr-2 h-4 w-4" />
                        Mark CWE
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        :disabled="isBulkProcessing"
                        @click="bulkDelete"
                    >
                        <TrashIcon class="mr-2 h-4 w-4" />
                        Delete
                    </Button>
                    <Button variant="ghost" size="sm" @click="clearSelection">
                        Clear
                    </Button>
                </template>

                <!-- Selection mode toggle -->
                <Button
                    v-if="hasWordData"
                    :variant="isSelectionMode ? 'default' : 'outline'"
                    size="sm"
                    @click="toggleSelectionMode"
                >
                    <template v-if="isSelectionMode">
                        <XMarkIcon class="mr-2 h-4 w-4" />
                        Exit Select
                    </template>
                    <template v-else>
                        <CheckIcon class="mr-2 h-4 w-4" />
                        Select
                    </template>
                </Button>
                <!-- Re-generate alignment button -->
                <DropdownMenu v-if="hasWordData">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="alignmentForm.processing"
                            @click="fetchAlignmentProviders"
                        >
                            <ArrowPathIcon
                                :class="[
                                    'mr-2 h-4 w-4',
                                    alignmentForm.processing
                                        ? 'animate-spin'
                                        : '',
                                ]"
                            />
                            Re-align
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-64">
                        <DropdownMenuLabel
                            >Re-generate word alignment</DropdownMenuLabel
                        >
                        <DropdownMenuSeparator />
                        <div class="space-y-3 p-2">
                            <div class="space-y-1">
                                <label class="text-xs font-medium"
                                    >Provider</label
                                >
                                <select
                                    v-model="alignmentForm.provider"
                                    class="w-full rounded-md border border-border bg-background px-2 py-1 text-sm"
                                    @change="
                                        alignmentForm.model =
                                            alignmentProviders.find(
                                                (p) =>
                                                    p.id ===
                                                    alignmentForm.provider,
                                            )?.default_model || ''
                                    "
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
                            <div
                                v-if="availableModels.length > 1"
                                class="space-y-1"
                            >
                                <label class="text-xs font-medium">Model</label>
                                <select
                                    v-model="alignmentForm.model"
                                    class="w-full rounded-md border border-border bg-background px-2 py-1 text-sm"
                                >
                                    <option
                                        v-for="model in availableModels"
                                        :key="model"
                                        :value="model"
                                    >
                                        {{ model }}
                                    </option>
                                </select>
                            </div>
                            <Button
                                size="sm"
                                class="w-full"
                                :disabled="
                                    alignmentForm.processing || !canAlign
                                "
                                @click="submitAlignment(true)"
                            >
                                {{
                                    alignmentForm.processing
                                        ? 'Starting...'
                                        : 'Overwrite & Re-align'
                                }}
                            </Button>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>

                <!-- Filter dropdown -->
                <DropdownMenu v-if="hasWordData">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm">
                            <FunnelIcon class="mr-2 h-4 w-4" />
                            {{
                                confidenceOptions.find(
                                    (o) => o.value === confidenceThreshold,
                                )?.label || 'Filter'
                            }}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <DropdownMenuLabel
                            >Filter by confidence</DropdownMenuLabel
                        >
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

        <!-- Action bar for active word -->
        <Teleport to="body">
            <div
                v-if="activeWord"
                ref="actionBarRef"
                class="fixed z-50 flex items-center gap-1 rounded-lg border border-border bg-popover p-1 shadow-lg"
                :style="actionBarStyle"
            >
                <span
                v-if="activeWord.confidence"
                class="px-2 text-xs text-muted-foreground"
                    v-tippy="'Confidence'"
                >
                    {{ Math.round(ensureNumber(activeWord.confidence) * 100) }}%
                </span>
                <Button
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7"
                    v-tippy="'Play snippet (P)'"
                    @click="doWordAction(playWord)"
                >
                    <SpeakerWaveIcon class="h-3.5 w-3.5" />
                </Button>
                <Button
                    v-if="!activeWord.is_deleted"
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7"
                    v-tippy="'Edit word (E)'"
                    @click="doWordAction(startEdit)"
                >
                    <PencilIcon class="h-3.5 w-3.5" />
                </Button>
                <Button
                    v-if="!activeWord.is_deleted"
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7"
                    v-tippy="'Insert after (I)'"
                    @click="doWordAction((w) => startInsert(w.id))"
                >
                    <PlusIcon class="h-3.5 w-3.5" />
                </Button>
                <Button
                    v-if="!activeWord.is_deleted && !activeWord.is_inserted"
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7"
                    :class="
                        activeWord.is_critical_error
                            ? 'text-orange-500'
                            : 'text-muted-foreground'
                    "
                    v-tippy="activeWord.is_critical_error
                            ? 'Remove critical error flag (C)'
                            : 'Mark as critical error (C)'"
                    @click="doWordAction(toggleCriticalError)"
                >
                    <ExclamationTriangleIcon class="h-3.5 w-3.5" />
                </Button>
                <Button
                    v-if="activeWord.is_deleted"
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7"
                    v-tippy="'Restore word (D)'"
                    @click="doWordAction(restoreWord)"
                >
                    <ArrowPathIcon class="h-3.5 w-3.5" />
                </Button>
                <Button
                    v-else
                    size="icon"
                    variant="ghost"
                    class="h-7 w-7 text-destructive"
                    v-tippy="'Delete word (D)'"
                    @click="doWordAction(deleteWord)"
                >
                    <TrashIcon class="h-3.5 w-3.5" />
                </Button>
            </div>
        </Teleport>

        <!-- ==================== ALIGNMENT VIEW (with reference) ==================== -->
        <div v-if="viewMode === 'alignment'" class="space-y-4">
            <!-- View Mode Toggle -->
            <div class="flex items-center gap-2">
                <span class="text-xs text-muted-foreground">View:</span>
                <div
                    class="flex rounded-lg border border-border bg-background p-0.5"
                >
                    <button
                        @click="alignmentDisplayMode = 'line-by-line'"
                        :class="[
                            'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                            alignmentDisplayMode === 'line-by-line'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                    >
                        Line-by-Line
                    </button>
                    <button
                        @click="alignmentDisplayMode = 'panels'"
                        :class="[
                            'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                            alignmentDisplayMode === 'panels'
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                    >
                        Separate Panels
                    </button>
                </div>
            </div>

            <!-- ===== LINE-BY-LINE VIEW ===== -->
            <div
                v-if="alignmentDisplayMode === 'line-by-line'"
                class="rounded-lg border border-border bg-card p-4"
            >
                <div class="space-y-4">
                    <div
                        v-for="(chunk, chunkIndex) in chunkedAlignment"
                        :key="chunkIndex"
                        class="border-b border-border pb-4 last:border-b-0 last:pb-0"
                    >
                        <!-- Reference row -->
                        <div
                            class="mb-2 flex flex-wrap items-center gap-1"
                            dir="rtl"
                        >
                            <span
                                class="text-xs text-muted-foreground"
                                dir="ltr"
                                >Ref:</span
                            >
                            <template
                                v-for="(item, idx) in chunk"
                                :key="`ref-${chunkIndex}-${idx}`"
                            >
                                <span
                                    :class="[
                                        'rounded px-1.5 py-0.5 text-sm',
                                        item.type === 'correct'
                                            ? 'bg-muted'
                                            : item.type === 'sub'
                                              ? 'bg-amber-200 dark:bg-amber-900/50'
                                              : item.type === 'del'
                                                ? 'bg-rose-200 line-through dark:bg-rose-900/50'
                                                : 'text-muted-foreground',
                                    ]"
                                    >{{
                                        item.type === 'ins' ? '—' : item.ref
                                    }}</span
                                >
                            </template>
                        </div>
                        <!-- Hypothesis row -->
                        <div
                            class="flex flex-wrap items-center gap-1"
                            dir="rtl"
                        >
                            <span
                                class="text-xs text-muted-foreground"
                                dir="ltr"
                                >Hyp:</span
                            >
                            <template
                                v-for="(item, idx) in chunk"
                                :key="`hyp-${chunkIndex}-${idx}`"
                            >
                                <!-- Deletion placeholder -->
                                <span
                                    v-if="item.type === 'del'"
                                    class="px-1.5 py-0.5 text-sm text-muted-foreground"
                                    >—</span
                                >

                                <!-- Edit mode -->
                                <div
                                    v-else-if="
                                        item.wordData &&
                                        editingWordId === item.wordData.id
                                    "
                                    class="inline-flex items-center gap-1 rounded-md border-2 border-primary bg-primary/10 px-1 py-0.5"
                                >
                                    <input
                                        v-model="editValue"
                                        type="text"
                                        class="w-20 rounded border border-border bg-background px-2 py-1 text-sm sm:w-24"
                                        dir="auto"
                                        v-focus
                                        @keyup.enter="
                                            saveCorrection(item.wordData!)
                                        "
                                        @keyup.escape="cancelEdit"
                                    />
                                    <Button
                                        size="icon"
                                        class="h-7 w-7"
                                        :disabled="
                                            savingWordId === item.wordData.id
                                        "
                                        @click="
                                            saveCorrection(item.wordData!)
                                        "
                                    >
                                        <CheckIcon
                                            v-if="
                                                savingWordId !==
                                                item.wordData.id
                                            "
                                            class="h-4 w-4"
                                        />
                                        <span
                                            v-else
                                            class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent"
                                        />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="h-7 w-7"
                                        @click="cancelEdit"
                                    >
                                        <XMarkIcon class="h-4 w-4" />
                                    </Button>
                                </div>

                                <!-- Saving state -->
                                <div
                                    v-else-if="
                                        item.wordData &&
                                        savingWordId === item.wordData.id
                                    "
                                    class="inline-flex items-center gap-2 rounded-md border-2 border-destructive/50 bg-destructive/10 px-2 py-1 text-sm text-destructive"
                                >
                                    <span
                                        class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent"
                                    />
                                    <span class="opacity-70">{{
                                        item.wordData.corrected_word ||
                                        item.wordData.word
                                    }}</span>
                                </div>

                                <!-- Selection mode: click to toggle selection -->
                                <button
                                    v-else-if="item.wordData && isSelectionMode"
                                    :class="getAlignmentItemClass(item)"
                                    :disabled="
                                        savingWordId === item.wordData.id ||
                                        isBulkProcessing
                                    "
                                    @click="
                                        toggleWordSelection(item.wordData!.id)
                                    "
                                    @click.middle.prevent="
                                        playWord(item.wordData!)
                                    "
                                >
                                    <input
                                        type="checkbox"
                                        :checked="
                                            isWordSelected(item.wordData.id)
                                        "
                                        class="pointer-events-none h-3 w-3"
                                        @click.stop
                                    />
                                    <ExclamationTriangleIcon
                                        v-if="item.wordData.is_critical_error"
                                        class="h-3 w-3 text-orange-500"
                                    />
                                    <template
                                        v-if="
                                            item.wordData.corrected_word &&
                                            !item.wordData.is_deleted
                                        "
                                    >
                                        <span class="line-through opacity-50">{{
                                            item.hyp
                                        }}</span>
                                        <span>→</span>
                                        <span>{{
                                            item.wordData.corrected_word
                                        }}</span>
                                    </template>
                                    <template v-else>
                                        {{ item.hyp }}
                                    </template>
                                </button>
                                <!-- Normal mode: plain button, opens shared dropdown -->
                                <button
                                    v-else-if="
                                        item.wordData && !isSelectionMode
                                    "
                                    :class="getAlignmentItemClass(item)"
                                    :disabled="
                                        savingWordId === item.wordData.id
                                    "
                                    @click="
                                        openWordMenu(item.wordData!, $event)
                                    "
                                    @click.middle.prevent="
                                        playWord(item.wordData!)
                                    "
                                >
                                    <ExclamationTriangleIcon
                                        v-if="item.wordData.is_critical_error"
                                        class="h-3 w-3 text-orange-500"
                                    />
                                    <template
                                        v-if="
                                            item.wordData.corrected_word &&
                                            !item.wordData.is_deleted
                                        "
                                    >
                                        <span class="line-through opacity-50">{{
                                            item.hyp
                                        }}</span>
                                        <span>→</span>
                                        <span>{{
                                            item.wordData.corrected_word
                                        }}</span>
                                    </template>
                                    <template v-else>
                                        {{ item.hyp }}
                                    </template>
                                </button>

                                <!-- Non-interactive word -->
                                <span
                                    v-else
                                    :class="getAlignmentItemClass(item)"
                                    >{{ item.hyp }}</span
                                >

                                <!-- Insert input after word -->
                                <div
                                    v-if="
                                        item.wordData &&
                                        insertAfterWordId === item.wordData.id
                                    "
                                    class="flex items-center gap-1"
                                >
                                    <input
                                        v-model="insertValue"
                                        type="text"
                                        class="w-20 rounded-md border border-primary bg-background px-2 py-1 text-sm sm:w-24"
                                        placeholder="New word"
                                        dir="auto"
                                        v-focus
                                        @keyup.enter="insertWord"
                                        @keyup.escape="cancelInsert"
                                    />
                                    <Button
                                        size="icon"
                                        class="h-7 w-7"
                                        :disabled="insertForm.processing"
                                        @click="insertWord"
                                    >
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
                    </div>
                </div>

                <!-- Legend -->
                <div
                    class="mt-4 flex flex-wrap gap-2 border-t border-border pt-4 text-xs text-muted-foreground sm:gap-4"
                >
                    <div class="flex items-center gap-1">
                        <span class="h-3 w-3 rounded bg-muted" /> Correct
                    </div>
                    <div class="flex items-center gap-1">
                        <span
                            class="h-3 w-3 rounded bg-amber-200 dark:bg-amber-900/50"
                        />
                        Substitution
                    </div>
                    <div class="flex items-center gap-1">
                        <span
                            class="h-3 w-3 rounded bg-emerald-200 dark:bg-emerald-900/50"
                        />
                        Insertion
                    </div>
                    <div class="flex items-center gap-1">
                        <span
                            class="h-3 w-3 rounded bg-rose-200 dark:bg-rose-900/50"
                        />
                        Deletion
                    </div>
                    <div class="flex items-center gap-1">
                        <span
                            class="h-3 w-3 rounded border-2 border-orange-500/50 bg-orange-500/20"
                        />
                        Critical error
                    </div>
                </div>
            </div>

            <!-- ===== SEPARATE PANELS VIEW ===== -->
            <template v-if="alignmentDisplayMode === 'panels'">
                <!-- Reference row -->
                <div class="rounded-lg border border-border bg-card p-4">
                    <div
                        class="mb-2 text-xs font-semibold text-muted-foreground"
                    >
                        Reference (Ground Truth)
                    </div>
                    <div class="flex flex-wrap gap-1" dir="rtl">
                        <template
                            v-for="item in filteredAlignmentWithWords"
                            :key="'ref-' + item.alignIdx"
                        >
                            <span
                                v-if="item.type !== 'ins'"
                                :class="[
                                    'rounded px-1.5 py-0.5 text-sm',
                                    item.type === 'correct'
                                        ? 'bg-muted'
                                        : item.type === 'sub'
                                          ? 'bg-amber-200 dark:bg-amber-900/50'
                                          : item.type === 'del'
                                            ? 'bg-rose-200 line-through dark:bg-rose-900/50'
                                            : '',
                                ]"
                            >
                                {{ item.ref }}
                            </span>
                            <span
                                v-else
                                class="px-1.5 py-0.5 text-sm text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </div>
                </div>

                <!-- Hypothesis row (interactive) -->
                <div class="rounded-lg border border-border bg-card p-4">
                    <div
                        class="mb-2 text-xs font-semibold text-muted-foreground"
                    >
                        Hypothesis (ASR Output) — Click to edit
                    </div>
                    <div class="flex flex-wrap gap-1" dir="rtl">
                        <template
                            v-for="item in filteredAlignmentWithWords"
                            :key="'hyp-' + item.alignIdx"
                        >
                            <!-- Deletion placeholder -->
                            <span
                                v-if="item.type === 'del'"
                                class="px-1.5 py-0.5 text-sm text-muted-foreground"
                                >—</span
                            >

                            <!-- Edit mode -->
                            <div
                                v-else-if="
                                    item.wordData &&
                                    editingWordId === item.wordData.id
                                "
                                class="inline-flex items-center gap-1 rounded-md border-2 border-primary bg-primary/10 px-1 py-0.5"
                            >
                                <input
                                    v-model="editValue"
                                    type="text"
                                    class="w-20 rounded border border-border bg-background px-2 py-1 text-sm sm:w-24"
                                    dir="auto"
                                    v-focus
                                    @keyup.enter="
                                        saveCorrection(item.wordData!)
                                    "
                                    @keyup.escape="cancelEdit"
                                />
                                <Button
                                    size="icon"
                                    class="h-7 w-7"
                                    :disabled="
                                        savingWordId === item.wordData.id
                                    "
                                    @click="saveCorrection(item.wordData!)"
                                >
                                    <CheckIcon
                                        v-if="savingWordId !== item.wordData.id"
                                        class="h-4 w-4"
                                    />
                                    <span
                                        v-else
                                        class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent"
                                    />
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    class="h-7 w-7"
                                    @click="cancelEdit"
                                >
                                    <XMarkIcon class="h-4 w-4" />
                                </Button>
                            </div>

                            <!-- Saving state -->
                            <div
                                v-else-if="
                                    item.wordData &&
                                    savingWordId === item.wordData.id
                                "
                                class="inline-flex items-center gap-2 rounded-md border-2 border-destructive/50 bg-destructive/10 px-2 py-1 text-sm text-destructive"
                            >
                                <span
                                    class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent"
                                />
                                <span class="opacity-70">{{
                                    item.wordData.corrected_word ||
                                    item.wordData.word
                                }}</span>
                            </div>

                            <!-- Selection mode: click to toggle selection -->
                            <button
                                v-else-if="item.wordData && isSelectionMode"
                                :class="getAlignmentItemClass(item)"
                                :disabled="
                                    savingWordId === item.wordData.id ||
                                    isBulkProcessing
                                "
                                @click="toggleWordSelection(item.wordData!.id)"
                                @click.middle.prevent="playWord(item.wordData!)"
                            >
                                <input
                                    type="checkbox"
                                    :checked="isWordSelected(item.wordData.id)"
                                    class="pointer-events-none h-3 w-3"
                                    @click.stop
                                />
                                <ExclamationTriangleIcon
                                    v-if="item.wordData.is_critical_error"
                                    class="h-3 w-3 text-orange-500"
                                />
                                <template
                                    v-if="
                                        item.wordData.corrected_word &&
                                        !item.wordData.is_deleted
                                    "
                                >
                                    <span class="line-through opacity-50">{{
                                        item.hyp
                                    }}</span>
                                    <span>→</span>
                                    <span>{{
                                        item.wordData.corrected_word
                                    }}</span>
                                </template>
                                <template v-else>
                                    {{ item.hyp }}
                                </template>
                                <span
                                    v-if="item.wordData.confidence !== null"
                                    class="ml-0.5 text-[9px] opacity-50"
                                >
                                    {{
                                        Math.round(
                                            ensureNumber(
                                                item.wordData.confidence,
                                            ) * 100,
                                        )
                                    }}%
                                </span>
                            </button>
                            <!-- Normal mode: plain button, opens shared dropdown -->
                            <button
                                v-else-if="item.wordData && !isSelectionMode"
                                :class="getAlignmentItemClass(item)"
                                :disabled="savingWordId === item.wordData.id"
                                @click="openWordMenu(item.wordData!, $event)"
                                @click.middle.prevent="playWord(item.wordData!)"
                            >
                                <ExclamationTriangleIcon
                                    v-if="item.wordData.is_critical_error"
                                    class="h-3 w-3 text-orange-500"
                                />
                                <template
                                    v-if="
                                        item.wordData.corrected_word &&
                                        !item.wordData.is_deleted
                                    "
                                >
                                    <span class="line-through opacity-50">{{
                                        item.hyp
                                    }}</span>
                                    <span>→</span>
                                    <span>{{
                                        item.wordData.corrected_word
                                    }}</span>
                                </template>
                                <template v-else>
                                    {{ item.hyp }}
                                </template>
                                <span
                                    v-if="item.wordData.confidence !== null"
                                    class="ml-0.5 text-[9px] opacity-50"
                                >
                                    {{
                                        Math.round(
                                            ensureNumber(
                                                item.wordData.confidence,
                                            ) * 100,
                                        )
                                    }}%
                                </span>
                            </button>

                            <!-- Non-interactive word (no word data) -->
                            <span v-else :class="getAlignmentItemClass(item)">
                                {{ item.hyp }}
                            </span>

                            <!-- Insert input after word -->
                            <div
                                v-if="
                                    item.wordData &&
                                    insertAfterWordId === item.wordData.id
                                "
                                class="flex items-center gap-1"
                            >
                                <input
                                    v-model="insertValue"
                                    type="text"
                                    class="w-20 rounded-md border border-primary bg-background px-2 py-1 text-sm sm:w-24"
                                    placeholder="New word"
                                    dir="auto"
                                    v-focus
                                    @keyup.enter="insertWord"
                                    @keyup.escape="cancelInsert"
                                />
                                <Button
                                    size="icon"
                                    class="h-7 w-7"
                                    :disabled="insertForm.processing"
                                    @click="insertWord"
                                >
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
                </div>
            </template>

            <!-- Inserted words (user additions not in alignment) -->
            <div
                v-if="insertedWords.length > 0"
                class="border-success/30 bg-success/5 rounded-lg border p-4"
            >
                <div class="text-success mb-2 text-xs font-semibold">
                    Inserted Words
                </div>
                <div class="flex flex-wrap gap-2" dir="auto">
                    <template v-for="word in insertedWords" :key="word.id">
                        <button
                            :class="getWordClass(word)"
                            @click="openWordMenu(word, $event)"
                        >
                            {{ word.word }}
                        </button>
                    </template>
                </div>
            </div>

            <!-- Legend -->
            <div
                class="flex flex-wrap gap-2 border-t border-border pt-4 text-xs text-muted-foreground sm:gap-4"
            >
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded bg-muted" />
                    Correct
                </div>
                <div class="flex items-center gap-1">
                    <span
                        class="h-3 w-3 rounded bg-amber-200 dark:bg-amber-900/50"
                    />
                    Substitution
                </div>
                <div class="flex items-center gap-1">
                    <span
                        class="h-3 w-3 rounded bg-emerald-200 dark:bg-emerald-900/50"
                    />
                    Insertion
                </div>
                <div class="flex items-center gap-1">
                    <span
                        class="h-3 w-3 rounded bg-rose-200 dark:bg-rose-900/50"
                    />
                    Deletion
                </div>
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded bg-primary/20" />
                    Corrected
                </div>
                <div class="flex items-center gap-1">
                    <span
                        class="h-3 w-3 rounded border-2 border-orange-500/50 bg-orange-500/20"
                    />
                    Critical error
                </div>
            </div>
        </div>

        <!-- ==================== WORD-ONLY VIEW (no reference) ==================== -->
        <div
            v-else-if="viewMode === 'words'"
            class="rounded-lg border border-border bg-card p-4"
        >
            <div class="flex flex-wrap gap-2" dir="auto">
                <template
                    v-for="(chunk, chunkIndex) in chunkedWords"
                    :key="chunkIndex"
                >
                    <div
                        :ref="
                            (el) => observeChunk(chunkIndex, el as HTMLElement)
                        "
                        :data-chunk-index="chunkIndex"
                        class="contents"
                    >
                        <template v-if="visibleChunks.has(chunkIndex)">
                            <template v-for="word in chunk" :key="word.id">
                                <!-- Edit mode -->
                                <div
                                    v-if="editingWordId === word.id"
                                    class="inline-flex items-center gap-1 rounded-md border-2 border-primary bg-primary/10 px-1 py-0.5"
                                >
                                    <input
                                        v-model="editValue"
                                        type="text"
                                        class="w-20 rounded border border-border bg-background px-2 py-1 text-sm sm:w-24"
                                        dir="auto"
                                        v-focus
                                        @keyup.enter="saveCorrection(word)"
                                        @keyup.escape="cancelEdit"
                                    />
                                    <Button
                                        size="icon"
                                        class="h-7 w-7"
                                        :disabled="savingWordId === word.id"
                                        @click="saveCorrection(word)"
                                    >
                                        <CheckIcon
                                            v-if="savingWordId !== word.id"
                                            class="h-4 w-4"
                                        />
                                        <span
                                            v-else
                                            class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent"
                                        />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="h-7 w-7"
                                        @click="cancelEdit"
                                    >
                                        <XMarkIcon class="h-4 w-4" />
                                    </Button>
                                </div>

                                <!-- Saving state -->
                                <div
                                    v-else-if="savingWordId === word.id"
                                    class="inline-flex items-center gap-2 rounded-md border-2 border-destructive/50 bg-destructive/10 px-2 py-1 text-sm text-destructive"
                                >
                                    <span
                                        class="h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent"
                                    />
                                    <span class="opacity-70">{{
                                        word.corrected_word || word.word
                                    }}</span>
                                </div>

                                <!-- Selection mode: click to toggle selection -->
                                <button
                                    v-else-if="isSelectionMode"
                                    :class="getWordClass(word)"
                                    :disabled="
                                        savingWordId === word.id ||
                                        isBulkProcessing
                                    "
                                    @click="toggleWordSelection(word.id)"
                                    @click.middle.prevent="playWord(word)"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="isWordSelected(word.id)"
                                        class="pointer-events-none h-3 w-3"
                                        @click.stop
                                    />
                                    <ExclamationTriangleIcon
                                        v-if="word.is_critical_error"
                                        class="h-3.5 w-3.5 text-orange-500"
                                    />
                                    <template
                                        v-if="
                                            word.corrected_word &&
                                            !word.is_deleted
                                        "
                                    >
                                        <span class="line-through opacity-50">{{
                                            word.word
                                        }}</span>
                                        <span>→</span>
                                        <span>{{ word.corrected_word }}</span>
                                    </template>
                                    <template v-else>
                                        {{ word.word }}
                                    </template>

                                    <span
                                        v-if="
                                            word.confidence !== null &&
                                            !word.is_inserted
                                        "
                                        class="ml-1 text-[10px] opacity-60"
                                    >
                                        {{
                                            Math.round(
                                                ensureNumber(word.confidence) *
                                                    100,
                                            )
                                        }}%
                                    </span>
                                </button>

                                <!-- Normal mode: plain button, opens shared dropdown -->
                                <button
                                    v-else
                                    :class="getWordClass(word)"
                                    :disabled="savingWordId === word.id"
                                    @click="openWordMenu(word, $event)"
                                    @click.middle.prevent="playWord(word)"
                                >
                                    <ExclamationTriangleIcon
                                        v-if="word.is_critical_error"
                                        class="h-3.5 w-3.5 text-orange-500"
                                    />
                                    <template
                                        v-if="
                                            word.corrected_word &&
                                            !word.is_deleted
                                        "
                                    >
                                        <span class="line-through opacity-50">{{
                                            word.word
                                        }}</span>
                                        <span>→</span>
                                        <span>{{ word.corrected_word }}</span>
                                    </template>
                                    <template v-else>
                                        {{ word.word }}
                                    </template>

                                    <span
                                        v-if="
                                            word.confidence !== null &&
                                            !word.is_inserted
                                        "
                                        class="ml-1 text-[10px] opacity-60"
                                    >
                                        {{
                                            Math.round(
                                                ensureNumber(word.confidence) *
                                                    100,
                                            )
                                        }}%
                                    </span>
                                </button>

                                <!-- Insert input -->
                                <div
                                    v-if="insertAfterWordId === word.id"
                                    class="flex items-center gap-1"
                                >
                                    <input
                                        v-model="insertValue"
                                        type="text"
                                        class="w-20 rounded-md border border-primary bg-background px-2 py-1 text-sm sm:w-24"
                                        placeholder="New word"
                                        dir="auto"
                                        v-focus
                                        @keyup.enter="insertWord"
                                        @keyup.escape="cancelInsert"
                                    />
                                    <Button
                                        size="icon"
                                        class="h-7 w-7"
                                        :disabled="insertForm.processing"
                                        @click="insertWord"
                                    >
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
                        </template>
                        <!-- Placeholder for off-screen chunks -->
                        <span
                            v-else
                            class="inline-block h-8"
                            :style="{
                                width:
                                    chunk.length *
                                        (windowWidth < 640 ? 40 : 60) +
                                    'px',
                            }"
                        />
                    </div>
                </template>
            </div>

            <!-- Legend -->
            <div
                class="mt-4 flex flex-wrap gap-2 border-t border-border pt-4 text-xs text-muted-foreground sm:gap-4"
            >
                <div class="flex items-center gap-1">
                    <span class="bg-success/20 h-3 w-3 rounded" />
                    Inserted
                </div>
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded bg-primary/20" />
                    Corrected
                </div>
                <div class="flex items-center gap-1">
                    <span
                        class="h-3 w-3 rounded border-2 border-orange-500/50 bg-orange-500/20"
                    />
                    Critical error
                </div>
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded bg-destructive/15" />
                    Low confidence
                </div>
                <div class="flex items-center gap-1">
                    <span class="bg-warning/15 h-3 w-3 rounded" />
                    Medium confidence
                </div>
                <div class="flex items-center gap-1">
                    <span class="bg-success/15 h-3 w-3 rounded" />
                    High confidence
                </div>
            </div>
        </div>

        <!-- ==================== TEXT-ONLY VIEW (no word data) ==================== -->
        <div
            v-else
            class="rounded-lg border border-border bg-muted/50 p-8 text-center"
        >
            <p class="text-muted-foreground">
                No word-level data available for this transcription.
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Word timing data is captured when transcribing with supported
                ASR providers, or you can generate alignment using a forced
                alignment model.
            </p>

            <!-- Plain text display -->
            <div
                v-if="hypothesisText"
                class="mx-auto mt-4 max-w-2xl rounded-lg border border-border bg-card p-4 text-left"
            >
                <div
                    v-if="!isEditingText"
                    class="text-sm whitespace-pre-wrap"
                    dir="auto"
                >
                    {{ hypothesisText }}
                </div>
                <textarea
                    v-else
                    v-model="editedText"
                    rows="8"
                    class="w-full resize-y rounded-lg border border-border bg-background p-3 text-sm focus:border-primary focus:ring-2 focus:ring-primary/20"
                    dir="auto"
                />
                <div class="mt-3 flex justify-end gap-2">
                    <Button
                        v-if="!isEditingText"
                        variant="outline"
                        size="sm"
                        @click="
                            isEditingText = true;
                            editedText = hypothesisText || '';
                        "
                    >
                        <PencilIcon class="mr-2 h-4 w-4" />
                        Edit Text
                    </Button>
                    <template v-else>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="isEditingText = false"
                        >
                            Cancel
                        </Button>
                        <Button size="sm"> Save </Button>
                    </template>
                </div>
            </div>

            <!-- Alignment Options -->
            <div v-if="alignmentProviders.length > 0" class="mt-4 space-y-4">
                <Button
                    v-if="!showAlignmentOptions"
                    variant="outline"
                    @click="
                        showAlignmentOptions = true;
                        fetchAlignmentProviders();
                    "
                >
                    <ArrowPathIcon class="mr-2 h-4 w-4" />
                    Generate Word Alignment
                </Button>

                <div
                    v-if="showAlignmentOptions"
                    class="mx-auto max-w-md space-y-4 rounded-lg border border-border bg-card p-4 text-left"
                >
                    <h4 class="font-medium">Generate Word Alignment</h4>
                    <p class="text-xs text-muted-foreground">
                        Use a forced alignment model to create word-level timing
                        data.
                    </p>

                    <div class="space-y-2">
                        <label class="text-sm font-medium">Provider</label>
                        <select
                            v-model="alignmentForm.provider"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            @change="
                                alignmentForm.model =
                                    alignmentProviders.find(
                                        (p) => p.id === alignmentForm.provider,
                                    )?.default_model || ''
                            "
                        >
                            <option
                                v-for="provider in alignmentProviders"
                                :key="provider.id"
                                :value="provider.id"
                                :disabled="!provider.available"
                            >
                                {{ provider.name }}
                                {{
                                    provider.requires_credential &&
                                    !provider.has_credential
                                        ? '(No API key)'
                                        : ''
                                }}
                            </option>
                        </select>
                    </div>

                    <div v-if="availableModels.length > 0" class="space-y-2">
                        <label class="text-sm font-medium">Model</label>
                        <select
                            v-model="alignmentForm.model"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        >
                            <option
                                v-for="model in availableModels"
                                :key="model"
                                :value="model"
                            >
                                {{ model }}
                            </option>
                        </select>
                    </div>

                    <div
                        v-if="!canAlign"
                        class="bg-warning/10 text-warning rounded-md p-2 text-sm"
                    >
                        No API key configured for this provider. Add your API
                        key in Settings.
                    </div>

                    <div class="flex gap-2">
                        <Button
                            :disabled="alignmentForm.processing || !canAlign"
                            @click="submitAlignment(false)"
                        >
                            <ArrowPathIcon
                                v-if="alignmentForm.processing"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            <ArrowPathIcon v-else class="mr-2 h-4 w-4" />
                            {{
                                alignmentForm.processing
                                    ? 'Starting...'
                                    : 'Start Alignment'
                            }}
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
    </div>
</template>
