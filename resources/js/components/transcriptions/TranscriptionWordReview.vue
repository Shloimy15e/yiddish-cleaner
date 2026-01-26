<script setup lang="ts">
import {
    CheckIcon,
    PencilIcon,
    PlusIcon,
    TrashIcon,
    XMarkIcon,
    SpeakerWaveIcon,
    FunnelIcon,
} from '@heroicons/vue/24/outline';
import { computed, onMounted, ref, watch } from 'vue';

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
    WordReviewStats,
    WordReviewConfig,
} from '@/types/transcriptions';

interface Props {
    transcriptionId: number;
    audioPlayerRef?: {
        playRange: (start: number, end: number, padding?: number) => void;
    } | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    statsUpdated: [stats: WordReviewStats];
}>();

// State
const words = ref<TranscriptionWord[]>([]);
const stats = ref<WordReviewStats | null>(null);
const config = ref<WordReviewConfig>({
    playback_padding_seconds: 2,
    default_confidence_threshold: 0.7,
});
const isLoading = ref(true);
const error = ref<string | null>(null);

// Filter state
const confidenceThreshold = ref<string>('low'); // 'all' | 'low' | custom number
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
const savingWordId = ref<number | null>(null);

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
        // Always show inserted words and corrected words
        if (w.is_inserted || w.corrected_word !== null || w.is_deleted) {
            return true;
        }
        // Show if confidence is null (unknown) or below threshold
        return w.confidence === null || w.confidence <= threshold;
    });
});

const hasWordData = computed(() => words.value.length > 0);

// Fetch words from API
const fetchWords = async () => {
    isLoading.value = true;
    error.value = null;

    try {
        const response = await fetch(
            `/api/transcriptions/${props.transcriptionId}/words`,
        );
        if (!response.ok) throw new Error('Failed to fetch words');

        const data = await response.json();
        words.value = data.words;
        stats.value = data.stats;
        config.value = data.config;
        emit('statsUpdated', data.stats);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Unknown error';
    } finally {
        isLoading.value = false;
    }
};

// Play word audio snippet
const playWord = (word: TranscriptionWord) => {
    if (!props.audioPlayerRef) return;
    props.audioPlayerRef.playRange(
        word.start_time,
        word.end_time,
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

// Save word correction
const saveCorrection = async (word: TranscriptionWord) => {
    savingWordId.value = word.id;

    try {
        const response = await fetch(
            `/api/transcriptions/${props.transcriptionId}/words/${word.id}`,
            {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    corrected_word:
                        editValue.value === word.word ? null : editValue.value,
                }),
            },
        );

        if (!response.ok) throw new Error('Failed to save correction');

        const data = await response.json();

        // Update local state
        const index = words.value.findIndex((w) => w.id === word.id);
        if (index !== -1) {
            words.value[index] = data.word;
        }
        if (data.stats) {
            stats.value = data.stats;
            emit('statsUpdated', data.stats);
        }

        cancelEdit();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to save';
    } finally {
        savingWordId.value = null;
    }
};

// Delete word (mark as deleted)
const deleteWord = async (word: TranscriptionWord) => {
    savingWordId.value = word.id;

    try {
        if (word.is_inserted) {
            // Permanently delete inserted words
            const response = await fetch(
                `/api/transcriptions/${props.transcriptionId}/words/${word.id}`,
                {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') || '',
                    },
                },
            );

            if (!response.ok) throw new Error('Failed to delete word');

            const data = await response.json();

            // Remove from local state
            words.value = words.value.filter((w) => w.id !== word.id);
            if (data.stats) {
                stats.value = data.stats;
                emit('statsUpdated', data.stats);
            }
        } else {
            // Soft delete original ASR words
            const response = await fetch(
                `/api/transcriptions/${props.transcriptionId}/words/${word.id}`,
                {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({ is_deleted: true }),
                },
            );

            if (!response.ok) throw new Error('Failed to delete word');

            const data = await response.json();

            // Update local state
            const index = words.value.findIndex((w) => w.id === word.id);
            if (index !== -1) {
                words.value[index] = data.word;
            }
            if (data.stats) {
                stats.value = data.stats;
                emit('statsUpdated', data.stats);
            }
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to delete';
    } finally {
        savingWordId.value = null;
    }
};

// Restore deleted word
const restoreWord = async (word: TranscriptionWord) => {
    savingWordId.value = word.id;

    try {
        const response = await fetch(
            `/api/transcriptions/${props.transcriptionId}/words/${word.id}`,
            {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    is_deleted: false,
                    corrected_word: null,
                }),
            },
        );

        if (!response.ok) throw new Error('Failed to restore word');

        const data = await response.json();

        // Update local state
        const index = words.value.findIndex((w) => w.id === word.id);
        if (index !== -1) {
            words.value[index] = data.word;
        }
        if (data.stats) {
            stats.value = data.stats;
            emit('statsUpdated', data.stats);
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to restore';
    } finally {
        savingWordId.value = null;
    }
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

// Insert new word
const insertWord = async () => {
    if (!insertAfterWordId.value || !insertValue.value.trim()) return;

    try {
        const response = await fetch(
            `/api/transcriptions/${props.transcriptionId}/words`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    word: insertValue.value.trim(),
                    after_word_id: insertAfterWordId.value,
                }),
            },
        );

        if (!response.ok) throw new Error('Failed to insert word');

        const data = await response.json();

        // Insert into local state at correct position
        const afterIndex = words.value.findIndex(
            (w) => w.id === insertAfterWordId.value,
        );
        if (afterIndex !== -1) {
            words.value.splice(afterIndex + 1, 0, data.word);
        }
        if (data.stats) {
            stats.value = data.stats;
            emit('statsUpdated', data.stats);
        }

        cancelInsert();
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to insert';
    }
};

// Get confidence color class
const getConfidenceColor = (confidence: number | null): string => {
    if (confidence === null) return 'bg-muted text-muted-foreground';
    if (confidence >= 0.9) return 'bg-success/15 text-success';
    if (confidence >= 0.7) return 'bg-primary/15 text-primary';
    if (confidence >= 0.5) return 'bg-warning/15 text-warning';
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

// Load words on mount
onMounted(() => {
    fetchWords();
});

// Reload when transcription changes
watch(
    () => props.transcriptionId,
    () => {
        fetchWords();
    },
);
</script>

<template>
    <div class="space-y-4">
        <!-- Header with filter -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <h3 class="text-sm font-semibold text-foreground">
                    Word-Level Review
                </h3>
                <span
                    v-if="stats"
                    class="text-xs text-muted-foreground"
                >
                    ({{ stats.total_words }} words, {{ stats.correction_count }} corrections)
                </span>
            </div>

            <DropdownMenu>
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

        <!-- Loading state -->
        <div v-if="isLoading" class="flex items-center justify-center py-8">
            <div class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>

        <!-- Error state -->
        <div v-else-if="error" class="rounded-lg bg-destructive/10 p-4 text-destructive">
            {{ error }}
            <button class="ml-2 underline" @click="fetchWords">Retry</button>
        </div>

        <!-- No word data -->
        <div
            v-else-if="!hasWordData"
            class="rounded-lg border border-border bg-muted/50 p-8 text-center"
        >
            <p class="text-muted-foreground">
                No word-level data available for this transcription.
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Word timing data is captured when transcribing with supported ASR providers.
            </p>
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
                                :disabled="savingWordId === word.id"
                                @click.middle.prevent="playWord(word)"
                            >
                                <!-- Show corrected word with original struck through -->
                                <template v-if="word.corrected_word && !word.is_deleted">
                                    <span class="line-through opacity-50">{{ word.word }}</span>
                                    <span>→</span>
                                    <span>{{ word.corrected_word }}</span>
                                </template>
                                <template v-else>
                                    {{ word.word }}
                                </template>

                                <!-- Confidence indicator -->
                                <span
                                    v-if="word.confidence !== null && !word.is_inserted"
                                    class="ml-1 text-[10px] opacity-60"
                                >
                                    {{ Math.round(word.confidence * 100) }}%
                                </span>

                                <!-- Loading spinner -->
                                <span
                                    v-if="savingWordId === word.id"
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
                                    {{ word.start_time.toFixed(2) }}s - {{ word.end_time.toFixed(2) }}s
                                    <span v-if="word.confidence !== null">
                                        • {{ Math.round(word.confidence * 100) }}% confidence
                                    </span>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                
                                <DropdownMenuItem @click="playWord(word)">
                                    <SpeakerWaveIcon class="mr-2 h-4 w-4" />
                                    Play snippet
                                </DropdownMenuItem>

                                <DropdownMenuItem
                                    v-if="!word.is_deleted"
                                    @click="startEdit(word)"
                                >
                                    <PencilIcon class="mr-2 h-4 w-4" />
                                    Edit word
                                </DropdownMenuItem>

                                <DropdownMenuItem
                                    v-if="!word.is_deleted"
                                    @click="startInsert(word.id)"
                                >
                                    <PlusIcon class="mr-2 h-4 w-4" />
                                    Insert after
                                </DropdownMenuItem>

                                <DropdownMenuSeparator />

                                <DropdownMenuItem
                                    v-if="word.is_deleted"
                                    @click="restoreWord(word)"
                                >
                                    Restore word
                                </DropdownMenuItem>

                                <DropdownMenuItem
                                    v-else
                                    class="text-destructive focus:text-destructive"
                                    @click="deleteWord(word)"
                                >
                                    <TrashIcon class="mr-2 h-4 w-4" />
                                    Delete word
                                </DropdownMenuItem>
                            </template>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <!-- Insert input (appears after word when inserting) -->
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
                        <Button size="icon" class="h-7 w-7" @click="insertWord">
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
