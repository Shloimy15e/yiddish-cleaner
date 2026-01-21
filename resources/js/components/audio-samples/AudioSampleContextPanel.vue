<script setup lang="ts">
import {
    ArrowDownTrayIcon,
    ClipboardDocumentIcon,
    EllipsisHorizontalIcon,
} from '@heroicons/vue/24/outline';
import { computed, ref } from 'vue';

interface AudioMedia {
    size: number;
    mime_type: string;
}

interface AudioSample {
    id: number;
    name: string;
    status: string;
    created_at: string;
    reference_text_raw: string;
    reference_text_clean: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    processing_run: {
        preset: string;
        mode: string;
    } | null;
}

const props = defineProps<{
    audioSample: AudioSample;
    audioMedia: AudioMedia | null;
    hasAudio: boolean;
    hasRawText: boolean;
    hasCleanedText: boolean;
}>();

const emit = defineEmits<{ (e: 'delete'): void }>();

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        pending_transcript:
            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        imported:
            'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        cleaning:
            'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        cleaned:
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        validated:
            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    };
    return colors[status] ?? 'bg-muted text-muted-foreground';
};

const getStatusLabel = (status: string) => {
    const labels: Record<string, string> = {
        pending_transcript: 'Needs Transcript',
        imported: 'Needs Cleaning',
        cleaning: 'Cleaning...',
        cleaned: 'Ready for Review',
        validated: 'Benchmark Ready',
        failed: 'Failed',
    };
    return labels[status] ?? status;
};

const getCategoryColor = (cat: string | null) => {
    const colors: Record<string, string> = {
        excellent: 'clean-rate-excellent',
        good: 'clean-rate-good',
        moderate: 'clean-rate-moderate',
        low: 'clean-rate-low',
        poor: 'clean-rate-poor',
    };
    return colors[cat ?? ''] ?? 'bg-muted text-muted-foreground';
};

const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
};

const cleanRateLabel = computed(() => {
    if (props.audioSample.clean_rate === null) return null;
    return `${props.audioSample.clean_rate.toFixed(1)}% clean`;
});

const copied = ref<'name' | 'id' | null>(null);
const copyToClipboard = async (value: string, type: 'name' | 'id') => {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = type;
        setTimeout(() => (copied.value = null), 2000);
    } catch (error) {
        console.error('Failed to copy:', error);
    }
};
</script>

<template>
    <div class="shadow-glow-sm rounded-2xl border border-border bg-card/80 p-4 sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <div class="mb-2 flex flex-wrap items-center gap-3">
                    <h1 class="min-w-0 truncate text-2xl font-bold">
                        {{ audioSample.name }}
                    </h1>
                    <span
                        :class="[
                            'rounded-full px-3 py-1 text-xs font-medium whitespace-nowrap',
                            getStatusColor(audioSample.status),
                        ]"
                    >
                        {{ getStatusLabel(audioSample.status) }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <span class="inline-flex items-center rounded-full border border-border bg-muted/50 px-3 py-1">
                        Created {{ audioSample.created_at }}
                    </span>
                    <span
                        v-if="audioSample.processing_run"
                        class="inline-flex items-center rounded-full border border-border bg-muted/50 px-3 py-1"
                    >
                        {{ audioSample.processing_run.preset.replace(/_/g, ' ') }}
                    </span>
                    <span
                        v-if="audioMedia"
                        class="inline-flex items-center rounded-full border border-border bg-muted/50 px-3 py-1"
                    >
                        {{ formatFileSize(audioMedia.size) }} ·
                        {{ audioMedia.mime_type }}
                    </span>
                </div>

                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    <span
                        v-if="!hasAudio"
                        class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-rose-700"
                    >
                        Missing Audio
                    </span>
                    <span
                        v-if="!hasRawText"
                        class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-amber-700"
                    >
                        Missing Transcript
                    </span>
                    <span
                        v-if="hasRawText"
                        class="inline-flex items-center rounded-full border border-border bg-muted/50 px-2 py-0.5 text-muted-foreground"
                    >
                        Raw transcript
                    </span>
                    <span
                        v-if="hasCleanedText"
                        class="inline-flex items-center rounded-full border border-border bg-muted/50 px-2 py-0.5 text-muted-foreground"
                    >
                        Cleaned transcript
                    </span>
                    <span
                        v-if="cleanRateLabel"
                        :class="[
                            'inline-flex items-center rounded-full border px-2 py-0.5 text-xs',
                            getCategoryColor(audioSample.clean_rate_category),
                        ]"
                    >
                        {{ cleanRateLabel }}
                    </span>
                </div>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-end">
                <details class="group relative w-full sm:w-auto">
                    <summary
                        class="flex h-11 cursor-pointer list-none items-center justify-center gap-2 rounded-lg border bg-card px-4 text-sm font-medium hover:bg-muted sm:w-auto"
                    >
                        <ClipboardDocumentIcon class="h-4 w-4" />
                        Copy
                    </summary>
                    <div
                        class="absolute right-0 z-10 mt-1 w-48 rounded-lg border bg-popover p-1 shadow-lg"
                    >
                        <button
                            type="button"
                            @click="copyToClipboard(audioSample.name, 'name')"
                            class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-muted"
                        >
                            Copy name
                            <span v-if="copied === 'name'" class="text-xs text-emerald-600">Copied</span>
                        </button>
                        <button
                            type="button"
                            @click="copyToClipboard(String(audioSample.id), 'id')"
                            class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-muted"
                        >
                            Copy ID
                            <span v-if="copied === 'id'" class="text-xs text-emerald-600">Copied</span>
                        </button>
                    </div>
                </details>

                <details class="group relative w-full sm:w-auto">
                    <summary
                        class="flex h-11 cursor-pointer list-none items-center justify-center gap-2 rounded-lg border bg-card px-4 text-sm font-medium hover:bg-muted sm:w-auto"
                    >
                        <EllipsisHorizontalIcon class="h-4 w-4" />
                        More
                    </summary>
                    <div
                        class="absolute right-0 z-10 mt-1 w-56 rounded-lg border bg-popover p-1 shadow-lg"
                    >
                        <div
                            v-if="hasCleanedText"
                            class="px-2 pb-1 pt-2 text-xs font-semibold text-muted-foreground"
                        >
                            Downloads
                        </div>
                        <a
                            v-if="hasCleanedText"
                            :href="`/audio-samples/${audioSample.id}/download`"
                            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"
                        >
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            Cleaned (.docx)
                        </a>
                        <a
                            v-if="hasCleanedText"
                            :href="`/audio-samples/${audioSample.id}/download/text`"
                            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"
                        >
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            Cleaned (.txt)
                        </a>
                        <a
                            v-if="hasRawText"
                            :href="`/audio-samples/${audioSample.id}/download/original`"
                            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"
                        >
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            Original (.txt)
                        </a>
                        <div class="my-1 h-px bg-border"></div>
                        <button
                            type="button"
                            @click="emit('delete')"
                            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-destructive hover:bg-destructive/10"
                        >
                            Delete sample
                        </button>
                    </div>
                </details>
            </div>
        </div>
    </div>
</template>
