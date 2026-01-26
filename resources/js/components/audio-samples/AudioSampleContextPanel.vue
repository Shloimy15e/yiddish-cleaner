<script setup lang="ts">
import {
    ArrowDownTrayIcon,
    ClipboardDocumentIcon,
    EllipsisHorizontalIcon,
} from '@heroicons/vue/24/outline';
import { computed, ref } from 'vue';

import {
    getAudioSampleStatusClass,
    getAudioSampleStatusLabel,
} from '@/lib/audioSampleStatus';
import { getCleanRateCategoryClass } from '@/lib/cleanRate';
import { formatCreatedBy } from '@/lib/createdBy';
import { formatDateTime, formatTimeAgo } from '@/lib/date';
import type { AudioMedia, AudioSampleContext } from '@/types/audio-samples';

const props = defineProps<{
    audioSample: AudioSampleContext;
    audioMedia: AudioMedia | null;
    hasAudio: boolean;
    hasRawText: boolean;
    hasCleanedText: boolean;
}>();

const emit = defineEmits<{ (e: 'delete'): void }>();

const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
};

const cleanRateLabel = computed(() => {
    const bt = props.audioSample.base_transcription;
    if (!bt || bt.clean_rate === null) return null;
    return `${bt.clean_rate.toFixed(1)}% clean`;
});

const cleanRateCategory = computed(() => {
    const bt = props.audioSample.base_transcription;
    if (!bt) return null;
    // Derive category from clean_rate
    const rate = bt.clean_rate;
    if (rate === null) return null;
    if (rate >= 90) return 'excellent';
    if (rate >= 70) return 'good';
    if (rate >= 50) return 'fair';
    return 'needs-work';
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
    <div class="rounded-xl border border-border bg-card">
        <!-- Header -->
        <div class="flex items-center justify-between border-b px-6 py-4">
            <div class="flex items-center gap-3 min-w-0">
                <h1 class="text-xl font-semibold truncate">
                    {{ audioSample.name }}
                </h1>
                <span
                    :class="[
                        'rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                        getAudioSampleStatusClass(audioSample.status),
                    ]"
                >
                    {{ getAudioSampleStatusLabel(audioSample.status) }}
                </span>
            </div>
            
            <div class="flex items-center gap-2 shrink-0">
                <details class="group relative">
                    <summary
                        class="flex h-9 cursor-pointer list-none items-center justify-center gap-2 rounded-lg border bg-card px-3 text-sm font-medium hover:bg-muted"
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
                            <span
                                v-if="copied === 'name'"
                                class="text-xs text-emerald-600"
                                >Copied</span
                            >
                        </button>
                        <button
                            type="button"
                            @click="copyToClipboard(String(audioSample.id), 'id')"
                            class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-muted"
                        >
                            Copy ID
                            <span
                                v-if="copied === 'id'"
                                class="text-xs text-emerald-600"
                                >Copied</span
                            >
                        </button>
                    </div>
                </details>

                <details class="group relative">
                    <summary
                        class="flex h-9 cursor-pointer list-none items-center justify-center gap-2 rounded-lg border bg-card px-3 text-sm font-medium hover:bg-muted"
                    >
                        <EllipsisHorizontalIcon class="h-4 w-4" />
                        More
                    </summary>
                    <div
                        class="absolute right-0 z-10 mt-1 w-56 rounded-lg border bg-popover p-1 shadow-lg"
                    >
                        <div
                            v-if="hasCleanedText"
                            class="px-2 pt-2 pb-1 text-xs font-semibold text-muted-foreground"
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
        
        <!-- Metadata -->
        <div class="px-6 py-4">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-muted-foreground">
                <span v-tippy="formatDateTime(audioSample.created_at)">
                    Created {{ formatTimeAgo(audioSample.created_at) }}
                </span>
                <span aria-hidden="true">•</span>
                <span>Created by {{ formatCreatedBy(audioSample.user, undefined) }}</span>
                <template v-if="audioSample.processing_run">
                    <span aria-hidden="true">•</span>
                    <span
                        :class="[
                            'inline-flex items-center rounded-full border px-2 py-0.5 text-xs',
                            cleanRateCategory ? getCleanRateCategoryClass(cleanRateCategory) : 'border-border',
                        ]"
                    >
                        <template v-if="audioSample.processing_run.mode === 'llm' && audioSample.processing_run.llm_provider">
                            {{ audioSample.processing_run.llm_provider }}<template v-if="audioSample.processing_run.llm_model"> / {{ audioSample.processing_run.llm_model }}</template>
                        </template>
                        <template v-else>
                            {{ audioSample.processing_run.preset.replace(/_/g, ' ') }}
                        </template>
                    </span>
                </template>
                <template v-if="audioMedia">
                    <span aria-hidden="true">•</span>
                    <span>
                        {{ formatFileSize(audioMedia.size) }} ·
                        {{ audioMedia.mime_type }}
                    </span>
                </template>
            </div>

            <div class="mt-3 flex flex-wrap gap-2 text-xs">
                <span
                    v-if="!hasAudio"
                    class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-rose-700 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-400"
                >
                    Missing Audio
                </span>
                <span
                    v-if="!hasRawText"
                    class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-400"
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
                        cleanRateCategory ? getCleanRateCategoryClass(cleanRateCategory) : '',
                    ]"
                >
                    {{ cleanRateLabel }}
                </span>
            </div>
        </div>
    </div>
</template>
