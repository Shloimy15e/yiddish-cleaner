<script setup lang="ts">
import {
    AcademicCapIcon,
    AdjustmentsHorizontalIcon,
    DocumentTextIcon,
    InformationCircleIcon,
} from '@heroicons/vue/24/outline';

import AudioPlayer from '@/components/AudioPlayer.vue';
import type { BreadcrumbItem } from '@/types';
import type { AudioMedia, Preset } from '@/types/audio-samples';
import type { LlmProvider } from '@/types/transcription-show';
import type { AsrTranscription, BaseTranscription, SegmentReviewStats, Transcription, WordReviewStats } from '@/types/transcriptions';

const props = defineProps<{
    transcription: Transcription;
    audioSample?: {
        id: number;
        name: string;
        base_transcription?: {
            text_clean: string | null;
        } | null;
    } | null;
    audioMedia?: AudioMedia | null;
    presets?: Record<string, Preset>;
    llmProviders?: Record<string, LlmProvider>;
    wordReview?: {
        words: Array<Record<string, unknown>>;
        stats: WordReviewStats;
        config: {
            playback_padding_seconds: number;
            default_confidence_threshold: number;
        };
    } | null;
    segmentReview?: {
        segments: Array<Record<string, unknown>>;
        stats: SegmentReviewStats;
        config: {
            playback_padding_seconds: number;
            default_confidence_threshold: number;
        };
    } | null;
}>();

// Determine transcription type
const isBase = computed(() => props.transcription.type === 'base');
const isAsr = computed(() => props.transcription.type === 'asr');

// Cast to specific types
const baseTranscription = computed(() =>
    isBase.value ? props.transcription as BaseTranscription : null
);
const asrTranscription = computed(() =>
    isAsr.value ? props.transcription as AsrTranscription : null
);

// Breadcrumbs
const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    if (isBase.value) {
        return [
            { title: 'Dashboard', href: route('dashboard') },
            { title: 'Base Transcriptions', href: route('transcriptions.index') },
            { title: baseTranscription.value?.name || `Transcription #${props.transcription.id}`, href: '#' },
        ];
    } else {
        return [
            { title: 'Dashboard', href: route('dashboard') },
            { title: 'Audio Samples', href: route('audio-samples.index') },
            { title: props.audioSample?.name || 'Audio Sample', href: props.audioSample ? route('audio-samples.show', { audioSample: props.audioSample.id }) : '#' },
            { title: 'ASR Transcription', href: '#' },
        ];
    }
});

// ==================== ASR TRANSCRIPTION STATE ====================

// Word Review State
const audioPlayerRef = ref<InstanceType<typeof AudioPlayer> | null>(null);
const wordReviewStats = ref<WordReviewStats | null>(null);

// Segment Review State
const segmentReviewStats = ref<SegmentReviewStats | null>(null);
const showSegmentReview = ref(false); // Collapsed by default, word review is primary now

// Training flag toggle
const trainingFlagForm = useForm({});
const toggleTrainingFlag = () => {
    if (!props.audioSample || !asrTranscription.value) return;

    trainingFlagForm.post(route('transcriptions.toggle-training', { transcription: props.transcription.id }), {
        preserveScroll: true,
        only: ['transcription'],
    });
};

const handleWordReviewStats = (stats: WordReviewStats) => {
    wordReviewStats.value = stats;
};

const handleSegmentReviewStats = (stats: SegmentReviewStats) => {
    segmentReviewStats.value = stats;
};

// WER Range Selection
const showRangeModal = ref(false);

// Tokenized word counts for range display in header
const totalRefWords = computed(() => tokenize(referenceText.value).length);
const totalHypWords = computed(() => tokenize(hypothesisText.value).length);

// Check if a custom range is currently applied
const hasCustomRange = computed(() => {
    const t = asrTranscription.value;
    if (!t) return false;
    return t.wer_ref_start !== null || t.wer_ref_end !== null ||
           t.wer_hyp_start !== null || t.wer_hyp_end !== null;
});

// Format range display
const formatRange = (start: number | null, end: number | null, total: number) => {
    if (total === 0) return 'None';
    if (start === null && end === null) return `All (0-${total - 1})`;
    const s = start ?? 0;
    const e = end ?? (total - 1);
    return `${s}-${e}`;
};

// Format error rate for display
const formatErrorRate = (rate: number | null | undefined): string => {
    if (rate === null || rate === undefined) return 'N/A';
    return `${rate.toFixed(1)}%`;
};

// Get color class for Custom WER value
const getCustomWerColor = (rate: number | null | undefined): string => {
    if (rate === null || rate === undefined) return 'text-muted-foreground';
    if (rate === 0) return 'text-emerald-600';
    if (rate < 2) return 'text-emerald-500';
    if (rate < 5) return 'text-yellow-500';
    if (rate < 10) return 'text-orange-500';
    return 'text-red-500';
};

// Get reference text for ASR comparison
const referenceText = computed(() => {
    if (isAsr.value && props.audioSample?.base_transcription?.text_clean) {
        return decodeHtmlEntities(props.audioSample.base_transcription.text_clean);
    }
    return '';
});

const hypothesisText = computed(() => asrTranscription.value?.hypothesis_text ?? '');

// ==================== SHARED HELPERS ====================
// formatStatus and statusClass are imported from @/lib/transcriptionUtils
</script>

<template>

    <Head :title="isBase ? (baseTranscription?.name || 'Base Transcription') : 'ASR Transcription'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">

            <!-- ==================== BASE TRANSCRIPTION VIEW ==================== -->
            <BaseTranscriptionView
                v-if="isBase && baseTranscription"
                :transcription="baseTranscription"
                :audio-sample="audioSample"
                :audio-media="audioMedia"
                :presets="presets"
                :llm-providers="llmProviders"
            />

            <!-- ==================== ASR TRANSCRIPTION VIEW ==================== -->
            <template v-else-if="isAsr && asrTranscription">
                <!-- Header -->
                <div class="flex flex-col gap-4 rounded-xl border bg-card p-4 sm:p-6">
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h1 class="text-2xl font-bold">ASR Transcription</h1>
                            <p class="text-sm text-muted-foreground">
                                {{ asrTranscription.model_name }}
                                <span v-if="asrTranscription.model_version">(v{{ asrTranscription.model_version }})</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', statusClass(asrTranscription.status)]">
                                {{ formatStatus(asrTranscription.status) }}
                            </span>
                            <Link v-if="audioSample" :href="route('audio-samples.show', { audioSample: audioSample.id })" class="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted">
                            Back to sample
                            </Link>
                        </div>
                    </div>

                    <div class="grid gap-3 grid-cols-2 sm:gap-4 sm:grid-cols-3">
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">Source</div>
                            <div class="font-medium">{{ asrTranscription.source === 'generated' ? 'API' : 'Manual' }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">
                                <span class="inline-flex items-center gap-1">
                                    Custom WER
                                    <InformationCircleIcon class="h-4 w-4" v-tippy="'Standard WER with only critical substitutions counted. Insertions + deletions from Levenshtein, substitutions only if marked critical in review.'" />
                                </span>
                            </div>
                            <div class="font-mono font-semibold" :class="getCustomWerColor(asrTranscription.custom_wer)">
                                {{ formatErrorRate(asrTranscription.custom_wer) }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ asrTranscription.custom_wer_error_count ?? 0 }} / {{ asrTranscription.reviewed_word_count ?? 0 }} words
                                <span v-if="hasCustomRange">(custom range)</span>
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                {{ asrTranscription.custom_wer_insertion_count ?? 0 }} ins
                                <span class="mx-0.5">&middot;</span>
                                {{ asrTranscription.custom_wer_deletion_count ?? 0 }} del
                                <span class="mx-0.5">&middot;</span>
                                {{ asrTranscription.custom_wer_critical_replacement_count ?? 0 }} / {{ asrTranscription.custom_wer_replacement_count ?? 0 }} crit repl
                            </div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">
                                <span class="inline-flex items-center gap-1">
                                    WER
                                    <InformationCircleIcon class="h-4 w-4" v-tippy="'Standard WER (automated Levenshtein-based)'" />
                                </span>
                            </div>
                            <div class="font-mono text-muted-foreground">{{ formatErrorRate(asrTranscription.wer) }}</div>
                            <div v-if="hasCustomRange" class="text-xs text-muted-foreground">
                                (custom range)
                            </div>
                        </div>
                    </div>

                    <div v-if="asrTranscription.notes" class="text-sm text-muted-foreground">
                        {{ asrTranscription.notes }}
                    </div>
                </div>

                <!-- Transcription Review Section -->
                <div class="rounded-xl border border-border bg-card">
                    <!-- Header with WER metrics and training flag -->
                    <div class="flex flex-col gap-3 border-b border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 sm:py-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/15">
                                <DocumentTextIcon class="h-5 w-5 text-primary" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="font-semibold text-foreground">Transcription Review</h2>
                                <p class="text-xs text-muted-foreground">
                                    <span :class="getCustomWerColor(asrTranscription.custom_wer)" class="font-medium">
                                        Custom WER: {{ formatErrorRate(asrTranscription.custom_wer) }}
                                    </span>
                                    <span class="mx-1">•</span>
                                    {{ asrTranscription.custom_wer_insertion_count ?? 0 }} ins
                                    <span class="mx-0.5">&middot;</span>
                                    {{ asrTranscription.custom_wer_deletion_count ?? 0 }} del
                                    <span class="mx-0.5">&middot;</span>
                                    {{ asrTranscription.custom_wer_critical_replacement_count ?? 0 }} crit repl
                                    <template v-if="referenceText">
                                        <span class="mx-1">•</span>
                                        <span class="text-muted-foreground">WER: {{ asrTranscription.wer?.toFixed(1) ?? 'N/A' }}%</span>
                                    </template>
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                            <!-- WER Range Selection (only when reference available) -->
                            <button
                                v-if="referenceText && hypothesisText"
                                @click="showRangeModal = true"
                                :class="['inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted', hasCustomRange ? 'border-primary text-primary' : '']"
                            >
                                <AdjustmentsHorizontalIcon class="h-4 w-4" />
                                {{ hasCustomRange ? 'Edit Range' : 'Set Range' }}
                            </button>

                            <!-- Training Flag Toggle -->
                            <button
                                @click="toggleTrainingFlag"
                                :disabled="trainingFlagForm.processing"
                                :class="[
                                    'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                    props.transcription.flagged_for_training
                                        ? 'border-success bg-success/10 text-success hover:bg-success/20'
                                        : 'border-border hover:bg-muted'
                                ]"
                                :title="props.transcription.flagged_for_training ? 'Remove from training dataset' : 'Flag for training dataset'"
                            >
                                <AcademicCapIcon class="h-4 w-4" />
                                {{ props.transcription.flagged_for_training ? 'Training Data' : 'Flag for Training' }}
                            </button>
                        </div>
                    </div>

                    <!-- Audio Player -->
                    <div v-if="audioMedia" class="border-b border-border p-4">
                        <AudioPlayer
                            ref="audioPlayerRef"
                            :src="audioMedia.url"
                            :name="audioMedia.name"
                            :file-size="audioMedia.size"
                        />
                    </div>

                    <!-- WER Metrics Cards (collapsed) -->
                    <div v-if="referenceText && hypothesisText" class="border-b border-border">
                        <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div class="flex flex-wrap items-center gap-3 text-sm sm:gap-4">
                                <span class="flex items-center gap-1.5 text-emerald-600">
                                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                                    {{ asrTranscription.insertions ?? 0 }} ins
                                </span>
                                <span class="flex items-center gap-1.5 text-rose-600">
                                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-rose-500"></span>
                                    {{ asrTranscription.deletions ?? 0 }} del
                                </span>
                                <span class="flex items-center gap-1.5 text-amber-500">
                                    <span class="inline-block h-2.5 w-2.5 rounded-full bg-amber-500"></span>
                                    {{ asrTranscription.substitutions ?? 0 }} sub
                                </span>
                            </div>
                            <div v-if="hasCustomRange" class="text-xs text-muted-foreground">
                                Range: Ref {{ formatRange(asrTranscription.wer_ref_start, asrTranscription.wer_ref_end, totalRefWords) }} |
                                Hyp {{ formatRange(asrTranscription.wer_hyp_start, asrTranscription.wer_hyp_end, totalHypWords) }}
                            </div>
                        </div>
                    </div>

                    <!-- Unified Review Component -->
                    <div class="p-4 sm:p-6">
                        <TranscriptionReview
                            :transcription-id="props.transcription.id"
                            :word-review="props.wordReview ?? null"
                            :reference-text="referenceText"
                            :hypothesis-text="hypothesisText"
                            :audio-player-ref="audioPlayerRef"
                            @stats-updated="handleWordReviewStats"
                        />
                    </div>

                    <!-- Segment Review (optional, shown below word review) -->
                    <div v-if="props.segmentReview && props.segmentReview.segments.length > 0" class="border-t border-border">
                        <div class="flex items-center justify-between px-4 py-3 sm:px-6 sm:py-4">
                            <div class="flex items-center gap-3">
                                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-secondary/15">
                                    <DocumentTextIcon class="h-4 w-4 text-secondary" />
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-foreground">Segment Review</h3>
                                    <p class="text-xs text-muted-foreground">
                                        Review by sentence
                                        <span v-if="segmentReviewStats">
                                            • {{ segmentReviewStats.correction_count }} corrections
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <button
                                @click="showSegmentReview = !showSegmentReview"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted"
                            >
                                {{ showSegmentReview ? 'Hide' : 'Show' }}
                            </button>
                        </div>
                        <div v-show="showSegmentReview" class="p-4 pt-0 sm:p-6 sm:pt-0">
                            <TranscriptionSegmentReview
                                :transcription-id="props.transcription.id"
                                :segment-review="props.segmentReview ?? null"
                                :audio-player-ref="audioPlayerRef"
                                @stats-updated="handleSegmentReviewStats"
                            />
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- WER Range Selection Modal -->
        <WerRangeModal
            v-if="isAsr && asrTranscription && audioSample"
            :is-open="showRangeModal"
            :reference-text="referenceText"
            :hypothesis-text="hypothesisText"
            :audio-sample-id="audioSample.id"
            :transcription-id="asrTranscription.id"
            :initial-ref-start="asrTranscription.wer_ref_start"
            :initial-ref-end="asrTranscription.wer_ref_end"
            :initial-hyp-start="asrTranscription.wer_hyp_start"
            :initial-hyp-end="asrTranscription.wer_hyp_end"
            @close="showRangeModal = false"
        />
    </AppLayout>
</template>
