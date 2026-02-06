<script setup lang="ts">
import {
    CheckIcon,
    FunnelIcon,
    PencilIcon,
    SpeakerWaveIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger
} from '@/components/ui/dropdown-menu';
import type {
    SegmentReviewConfig,
    SegmentReviewStats,
    TranscriptionSegment,
} from '@/types/transcriptions';

interface SegmentReviewData {
    segments: TranscriptionSegment[] | Array<Record<string, unknown>>;
    stats: SegmentReviewStats;
    config: SegmentReviewConfig;
}

interface Props {
    transcriptionId: number;
    segmentReview: SegmentReviewData | null;
    audioPlayerRef?: {
        playRange: (start: number, end: number, padding?: number) => void;
    } | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    statsUpdated: [stats: SegmentReviewStats];
}>();

// State from props
const segments = computed(() => (props.segmentReview?.segments ?? []) as TranscriptionSegment[]);
const stats = computed(() => props.segmentReview?.stats ?? null);
const config = computed(() => props.segmentReview?.config ?? {
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
    { value: 'all', label: 'All segments' },
    { value: 'low', label: 'Low confidence only' },
    { value: '0.5', label: '≤ 50% confidence' },
    { value: '0.6', label: '≤ 60% confidence' },
    { value: '0.7', label: '≤ 70% confidence' },
    { value: '0.8', label: '≤ 80% confidence' },
    { value: '0.9', label: '≤ 90% confidence' },
];

// Edit state
const editingSegmentId = ref<number | null>(null);
const editValue = ref('');
const savingSegmentId = ref<number | null>(null);

// Form for segment operations
const correctionForm = useForm({
    corrected_text: null as string | null,
});

// Computed
const filteredSegments = computed(() => {
    if (confidenceThreshold.value === 'all') {
        return segments.value;
    }

    const threshold =
        confidenceThreshold.value === 'low'
            ? config.value.default_confidence_threshold
            : parseFloat(confidenceThreshold.value);

    return segments.value.filter((s) => {
        // Always show corrected segments
        if (s.corrected_text !== null) {
            return true;
        }
        // Show segments below threshold or with null confidence
        return s.confidence === null || s.confidence <= threshold;
    });
});

const hasSegmentData = computed(() => segments.value.length > 0);

// Helper to ensure number type
const ensureNumber = (value: unknown): number => {
    if (typeof value === 'number') return value;
    if (typeof value === 'string') return parseFloat(value) || 0;
    return 0;
};

const formatTime = (time: unknown): string => {
    const seconds = ensureNumber(time);
    const mins = Math.floor(seconds / 60);
    const secs = (seconds % 60).toFixed(1);
    return mins > 0 ? `${mins}:${secs.padStart(4, '0')}` : `${secs}s`;
};

const formatDuration = (start: unknown, end: unknown): string => {
    const duration = ensureNumber(end) - ensureNumber(start);
    return `${duration.toFixed(1)}s`;
};

// Play segment audio snippet
const playSegment = (segment: TranscriptionSegment) => {
    if (!props.audioPlayerRef) return;
    props.audioPlayerRef.playRange(
        ensureNumber(segment.start_time),
        ensureNumber(segment.end_time),
        config.value.playback_padding_seconds,
    );
};

// Start editing a segment
const startEdit = (segment: TranscriptionSegment) => {
    editingSegmentId.value = segment.id;
    editValue.value = segment.corrected_text ?? segment.text;
};

// Cancel editing
const cancelEdit = () => {
    editingSegmentId.value = null;
    editValue.value = '';
};

// Save segment correction using Inertia
const saveCorrection = (segment: TranscriptionSegment) => {
    savingSegmentId.value = segment.id;
    correctionForm.corrected_text = editValue.value === segment.text ? null : editValue.value;
    
    correctionForm.patch(
        `/transcriptions/${props.transcriptionId}/segments/${segment.id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelEdit();
            },
            onFinish: () => {
                savingSegmentId.value = null;
            },
        },
    );
};

// Clear correction
const clearCorrection = (segment: TranscriptionSegment) => {
    savingSegmentId.value = segment.id;
    correctionForm.corrected_text = null;
    
    correctionForm.patch(
        `/transcriptions/${props.transcriptionId}/segments/${segment.id}`,
        {
            preserveScroll: true,
            onFinish: () => {
                savingSegmentId.value = null;
            },
        },
    );
};

// Get confidence color class
const getConfidenceColor = (confidence: number | null): string => {
    if (confidence === null) return 'text-muted-foreground';
    const conf = ensureNumber(confidence);
    if (conf >= 0.9) return 'text-success';
    if (conf >= 0.7) return 'text-primary';
    if (conf >= 0.5) return 'text-warning';
    return 'text-destructive';
};

// Get segment card class
const getSegmentClass = (segment: TranscriptionSegment): string => {
    const base = 'rounded-lg border p-4 transition-colors';

    if (segment.corrected_text !== null) {
        return `${base} border-primary/50 bg-primary/5`;
    }

    const conf = segment.confidence;
    if (conf === null) return `${base} border-border bg-card`;
    if (conf >= 0.9) return `${base} border-success/30 bg-success/5`;
    if (conf >= 0.7) return `${base} border-primary/30 bg-primary/5`;
    if (conf >= 0.5) return `${base} border-warning/30 bg-warning/5`;
    return `${base} border-destructive/30 bg-destructive/5`;
};

// Get confidence badge class
const getConfidenceBadgeClass = (confidence: number | null): string => {
    const base = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium';
    if (confidence === null) return `${base} bg-muted text-muted-foreground`;
    const conf = ensureNumber(confidence);
    if (conf >= 0.9) return `${base} bg-success/15 text-success`;
    if (conf >= 0.7) return `${base} bg-primary/15 text-primary`;
    if (conf >= 0.5) return `${base} bg-warning/15 text-warning`;
    return `${base} bg-destructive/15 text-destructive`;
};
</script>

<template>
    <div class="space-y-4">
        <!-- Header with filter -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <h3 class="text-sm font-semibold text-foreground">
                    Segment-Level Review
                </h3>
                <span v-if="stats" class="text-xs text-muted-foreground">
                    ({{ stats.total_segments }} segments, {{ stats.correction_count }} corrections)
                </span>
            </div>

            <div class="flex items-center gap-2">
                <!-- Filter dropdown -->
                <DropdownMenu v-if="hasSegmentData">
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

        <!-- No segment data -->
        <div v-if="!hasSegmentData" class="rounded-lg border border-border bg-muted/50 p-8 text-center">
            <p class="text-muted-foreground">
                No segment-level data available for this transcription.
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Segment timing data is captured when transcribing with supported ASR providers.
            </p>
        </div>

        <!-- Segment list -->
        <div v-else class="space-y-3">
            <div
                v-for="segment in filteredSegments"
                :key="segment.id"
                :class="getSegmentClass(segment)"
            >
                <!-- Segment header -->
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">
                            {{ formatTime(segment.start_time) }} - {{ formatTime(segment.end_time) }}
                        </span>
                        <span class="opacity-60">
                            ({{ formatDuration(segment.start_time, segment.end_time) }})
                        </span>
                        <span v-if="segment.confidence !== null" :class="getConfidenceBadgeClass(segment.confidence)">
                            {{ Math.round(ensureNumber(segment.confidence) * 100) }}%
                        </span>
                    </div>

                    <div class="flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7"
                            title="Play segment"
                            @click="playSegment(segment)"
                        >
                            <SpeakerWaveIcon class="h-4 w-4" />
                        </Button>

                        <Button
                            v-if="editingSegmentId !== segment.id"
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7"
                            title="Edit segment"
                            @click="startEdit(segment)"
                        >
                            <PencilIcon class="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <!-- Edit mode -->
                <div v-if="editingSegmentId === segment.id" class="space-y-2">
                    <textarea
                        v-model="editValue"
                        class="min-h-[80px] w-full rounded-md border border-primary bg-background px-3 py-2 text-sm"
                        dir="auto"
                        autofocus
                        @keydown.escape="cancelEdit"
                    />
                    <div class="flex items-center gap-2">
                        <Button
                            size="sm"
                            :disabled="savingSegmentId === segment.id"
                            @click="saveCorrection(segment)"
                        >
                            <CheckIcon v-if="savingSegmentId !== segment.id" class="mr-2 h-4 w-4" />
                            <span v-else class="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            Save
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="cancelEdit"
                        >
                            Cancel
                        </Button>
                    </div>
                </div>

                <!-- Display mode -->
                <div v-else class="space-y-2">
                    <!-- Corrected text display -->
                    <div v-if="segment.corrected_text !== null" class="space-y-1">
                        <p class="text-sm text-muted-foreground line-through opacity-60" dir="auto">
                            {{ segment.text }}
                        </p>
                        <p class="text-sm font-medium text-foreground" dir="auto">
                            {{ segment.corrected_text }}
                        </p>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="h-6 px-2 text-xs text-muted-foreground"
                            :disabled="savingSegmentId === segment.id"
                            @click="clearCorrection(segment)"
                        >
                            <XMarkIcon class="mr-1 h-3 w-3" />
                            Clear correction
                        </Button>
                    </div>

                    <!-- Original text display -->
                    <p v-else class="text-sm text-foreground" dir="auto">
                        {{ segment.text }}
                    </p>
                </div>

                <!-- Loading overlay -->
                <div
                    v-if="savingSegmentId === segment.id && editingSegmentId !== segment.id"
                    class="absolute inset-0 flex items-center justify-center rounded-lg bg-background/50"
                >
                    <span class="h-5 w-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div v-if="hasSegmentData" class="flex flex-wrap gap-2 border-t border-border pt-4 text-xs text-muted-foreground sm:gap-4">
            <div class="flex items-center gap-1">
                <span class="h-3 w-3 rounded bg-primary/20 border border-primary/30" />
                Corrected
            </div>
            <div class="flex items-center gap-1">
                <span class="h-3 w-3 rounded bg-destructive/15 border border-destructive/30" />
                Low confidence (&lt;50%)
            </div>
            <div class="flex items-center gap-1">
                <span class="h-3 w-3 rounded bg-warning/15 border border-warning/30" />
                Medium confidence (50-70%)
            </div>
            <div class="flex items-center gap-1">
                <span class="h-3 w-3 rounded bg-success/15 border border-success/30" />
                High confidence (&gt;90%)
            </div>
        </div>
    </div>
</template>
