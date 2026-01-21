<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import * as Diff from 'diff';
import { computed, ref } from 'vue';
import { InformationCircleIcon } from '@heroicons/vue/24/outline';

interface AudioSample {
    id: number;
    name: string;
    reference_text_clean: string | null;
}

interface Transcription {
    id: number;
    model_name: string;
    model_version: string | null;
    source: 'generated' | 'imported';
    status: 'pending' | 'processing' | 'completed' | 'failed' | string;
    hypothesis_text: string | null;
    wer: number | null;
    cer: number | null;
    substitutions: number;
    insertions: number;
    deletions: number;
    reference_words: number;
    notes: string | null;
}

const props = defineProps<{
    audioSample: AudioSample;
    transcription: Transcription;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Audio Samples', href: '/audio-samples' },
    {
        title: props.audioSample.name,
        href: `/audio-samples/${props.audioSample.id}`,
    },
    { title: 'Transcription', href: '#' },
];

const referenceText = computed(() => props.audioSample.reference_text_clean ?? '');
const hypothesisText = computed(() => props.transcription.hypothesis_text ?? '');

const viewMode = ref<'alignment' | 'side-by-side'>('alignment');

type AlignmentItem = {
    type: 'correct' | 'sub' | 'ins' | 'del';
    ref: string | null;
    hyp: string | null;
};

const tokenize = (value: string) =>
    (value.match(/[^\s]+/g) || []).filter(Boolean);

const buildAlignmentFromDiff = (refText: string, hypText: string) => {
    const refTokens = tokenize(refText);
    const hypTokens = tokenize(hypText);
    const parts = Diff.diffArrays(refTokens, hypTokens);
    const alignment: AlignmentItem[] = [];

    let i = 0;
    while (i < parts.length) {
        const part = parts[i];
        const next = parts[i + 1];

        if (part.removed && next?.added) {
            const removedWords = part.value as string[];
            const addedWords = next.value as string[];
            const pairCount = Math.min(removedWords.length, addedWords.length);

            for (let idx = 0; idx < pairCount; idx++) {
                alignment.push({
                    type: 'sub',
                    ref: removedWords[idx],
                    hyp: addedWords[idx],
                });
            }

            for (let idx = pairCount; idx < removedWords.length; idx++) {
                alignment.push({
                    type: 'del',
                    ref: removedWords[idx],
                    hyp: null,
                });
            }

            for (let idx = pairCount; idx < addedWords.length; idx++) {
                alignment.push({
                    type: 'ins',
                    ref: null,
                    hyp: addedWords[idx],
                });
            }

            i += 2;
            continue;
        }

        if (part.added) {
            (part.value as string[]).forEach((word) => {
                alignment.push({ type: 'ins', ref: null, hyp: word });
            });
        } else if (part.removed) {
            (part.value as string[]).forEach((word) => {
                alignment.push({ type: 'del', ref: word, hyp: null });
            });
        } else {
            (part.value as string[]).forEach((word) => {
                alignment.push({ type: 'correct', ref: word, hyp: word });
            });
        }

        i += 1;
    }

    return alignment;
};

const alignment = computed(() => {
    if (!referenceText.value.trim() || !hypothesisText.value.trim()) return [];
    return buildAlignmentFromDiff(referenceText.value, hypothesisText.value);
});

const metrics = computed(() => {
    if (!alignment.value.length) {
        return {
            ins: 0,
            del: 0,
            sub: 0,
            wer: 0,
        };
    }

    let ins = 0;
    let del = 0;
    let sub = 0;

    for (const item of alignment.value) {
        if (item.type === 'ins') ins++;
        if (item.type === 'del') del++;
        if (item.type === 'sub') sub++;
    }

    const refCount = tokenize(referenceText.value).length || 1;
    const wer = ((ins + del + sub) / refCount) * 100;

    return { ins, del, sub, wer };
});

const chunkedAlignment = computed(() => {
    const perRow = 15;
    const chunks: AlignmentItem[][] = [];
    for (let i = 0; i < alignment.value.length; i += perRow) {
        chunks.push(alignment.value.slice(i, i + perRow));
    }
    return chunks;
});

const formatErrorRate = (rate: number | null): string => {
    if (rate === null) return 'N/A';
    const percent = rate > 1 ? rate : rate * 100;
    return `${percent.toFixed(2)}%`;
};

const formatStatus = (status: string) => {
    const map: Record<string, string> = {
        pending: 'Pending',
        processing: 'Processing',
        completed: 'Completed',
        failed: 'Failed',
    };
    return map[status] ?? status;
};

const statusClass = (status: string) => {
    const map: Record<string, string> = {
        pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    };
    return map[status] ?? 'bg-muted text-muted-foreground';
};
</script>

<template>
    <Head :title="`Transcription - ${audioSample.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="flex flex-col gap-4 rounded-xl border bg-card p-6">
                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">Transcription</h1>
                        <p class="text-sm text-muted-foreground">
                            {{ transcription.model_name }}
                            <span v-if="transcription.model_version">
                                (v{{ transcription.model_version }})
                            </span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span
                            :class="[
                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                statusClass(transcription.status),
                            ]"
                        >
                            {{ formatStatus(transcription.status) }}
                        </span>
                        <a
                            :href="`/audio-samples/${audioSample.id}`"
                            class="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted"
                        >
                            Back to sample
                        </a>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <div class="text-xs uppercase text-muted-foreground">Source</div>
                        <div class="font-medium">
                            {{
                                transcription.source === 'generated'
                                    ? 'API'
                                    : 'Manual'
                            }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-muted-foreground">
                            <span class="inline-flex items-center gap-1">
                                WER
                                <InformationCircleIcon
                                    class="h-4 w-4 text-muted-foreground"
                                    v-tippy="'Word Error Rate: percent of words that were wrong. Lower is better.'"
                                />
                            </span>
                        </div>
                        <div class="font-mono font-semibold">
                            {{ formatErrorRate(transcription.wer) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-muted-foreground">
                            <span class="inline-flex items-center gap-1">
                                CER
                                <InformationCircleIcon
                                    class="h-4 w-4 text-muted-foreground"
                                    v-tippy="'Character Error Rate: percent of characters that were wrong. Lower is better.'"
                                />
                            </span>
                        </div>
                        <div class="font-mono text-muted-foreground">
                            {{ formatErrorRate(transcription.cer) }}
                        </div>
                    </div>
                </div>

                <div v-if="transcription.notes" class="text-sm text-muted-foreground">
                    {{ transcription.notes }}
                </div>
            </div>

            <div
                v-if="!referenceText"
                class="rounded-xl border border-dashed bg-card p-6 text-center text-sm text-muted-foreground"
            >
                No cleaned reference transcript available for comparison.
            </div>

            <div
                v-else-if="!hypothesisText"
                class="rounded-xl border border-dashed bg-card p-6 text-center text-sm text-muted-foreground"
            >
                This transcription does not have hypothesis text yet.
            </div>

            <div v-else class="space-y-6">
                <div class="grid gap-4 md:grid-cols-4">
                    <div class="rounded-xl border bg-card p-4 text-center">
                        <div class="text-3xl font-bold text-emerald-600">
                            {{ metrics.ins }}
                        </div>
                        <div class="text-xs text-muted-foreground">Insertions</div>
                    </div>
                    <div class="rounded-xl border bg-card p-4 text-center">
                        <div class="text-3xl font-bold text-rose-600">
                            {{ metrics.del }}
                        </div>
                        <div class="text-xs text-muted-foreground">Deletions</div>
                    </div>
                    <div class="rounded-xl border bg-card p-4 text-center">
                        <div class="text-3xl font-bold text-amber-500">
                            {{ metrics.sub }}
                        </div>
                        <div class="text-xs text-muted-foreground">Substitutions</div>
                    </div>
                    <div class="rounded-xl border bg-card p-4 text-center">
                        <div class="text-3xl font-bold text-red-600">
                            {{ metrics.wer.toFixed(1) }}%
                        </div>
                        <div class="text-xs text-muted-foreground">WER</div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="viewMode = 'alignment'"
                        :class="[
                            'rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                            viewMode === 'alignment'
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted',
                        ]"
                    >
                        Alignment View
                    </button>
                    <button
                        type="button"
                        @click="viewMode = 'side-by-side'"
                        :class="[
                            'rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                            viewMode === 'side-by-side'
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted',
                        ]"
                    >
                        Side-by-Side View
                    </button>
                </div>

                <div
                    v-if="viewMode === 'alignment'"
                    class="rounded-xl border bg-card p-6"
                >
                    <div class="mb-4 font-semibold">Alignment Visualization</div>
                    <div class="space-y-4">
                        <div
                            v-for="(chunk, index) in chunkedAlignment"
                            :key="index"
                            class="border-b pb-4 last:border-b-0"
                            dir="rtl"
                        >
                            <div class="mb-2 flex flex-wrap gap-1">
                                <span class="text-xs text-muted-foreground">Ref:</span>
                                <span
                                    v-for="(item, idx) in chunk"
                                    :key="`ref-${index}-${idx}`"
                                    :class="[
                                        'rounded px-1.5 py-0.5 text-sm',
                                        item.type === 'correct'
                                            ? 'bg-muted'
                                            : item.type === 'sub'
                                              ? 'bg-amber-200'
                                              : item.type === 'del'
                                                ? 'bg-rose-200 line-through'
                                                : 'text-muted-foreground',
                                    ]"
                                >
                                    {{ item.type === 'ins' ? '—' : item.ref }}
                                </span>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <span class="text-xs text-muted-foreground">Hyp:</span>
                                <span
                                    v-for="(item, idx) in chunk"
                                    :key="`hyp-${index}-${idx}`"
                                    :class="[
                                        'rounded px-1.5 py-0.5 text-sm',
                                        item.type === 'correct'
                                            ? 'bg-muted'
                                            : item.type === 'sub'
                                              ? 'bg-amber-200'
                                              : item.type === 'ins'
                                                ? 'bg-emerald-200'
                                                : 'text-muted-foreground',
                                    ]"
                                >
                                    {{ item.type === 'del' ? '—' : item.hyp }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-4 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded bg-muted"></span>
                            Correct
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded bg-amber-200"></span>
                            Substitution
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded bg-emerald-200"></span>
                            Insertion
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="h-3 w-3 rounded bg-rose-200"></span>
                            Deletion
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="grid gap-4 md:grid-cols-2"
                >
                    <div class="rounded-xl border bg-card p-4" dir="rtl">
                        <div class="mb-2 text-sm font-semibold text-muted-foreground">
                            Reference (Cleaned)
                        </div>
                        <p class="whitespace-pre-wrap text-sm">
                            {{ referenceText }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4" dir="rtl">
                        <div class="mb-2 text-sm font-semibold text-muted-foreground">
                            Hypothesis
                        </div>
                        <p class="whitespace-pre-wrap text-sm">
                            {{ hypothesisText }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
