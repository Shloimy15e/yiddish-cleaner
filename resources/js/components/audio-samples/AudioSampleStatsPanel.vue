<script setup lang="ts">
import { getCleanRateCategoryClass } from '@/lib/cleanRate';
import type { AudioSampleDetail } from '@/types/audio-samples';

const props = defineProps<{
    hasCleanedText: boolean;
    audioSample: Pick<
        AudioSampleDetail,
        'clean_rate' | 'clean_rate_category' | 'removals'
    >;
    cleanedWords: number;
    originalWords: number;
    removedWords: number;
    reductionPercentage: string | number;
    formattedMetrics: Array<{ name: string; value: string | number }>;
    canBeValidated: boolean;
    isValidated: boolean;
    isEditing: boolean;
    validateProcessing: boolean;
}>();

const emit = defineEmits<{
    (e: 'validate'): void;
}>();
</script>

<template>
    <div v-if="hasCleanedText" class="space-y-4">
        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            <div class="rounded-xl @container border bg-card p-4 flex flex-col items-start justify-start">
                <div class="text-sm text-muted-foreground">Clean Rate</div>
                <div class="mt-1 flex flex-col @[150px]:flex-row items-center justify-start gap-2">
                    <span class="text-2xl font-bold">{{ audioSample.clean_rate ?? '-' }}%</span>
                    <span
                        v-if="audioSample.clean_rate_category"
                        :class="[
                            'rounded-full px-2 py-0.5 text-xs font-medium capitalize',
                            getCleanRateCategoryClass(audioSample.clean_rate_category),
                        ]"
                    >
                        {{ audioSample.clean_rate_category }}
                    </span>
                </div>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <div class="text-sm text-muted-foreground">Reduction</div>
                <div class="text-2xl font-bold text-blue-600">
                    {{ reductionPercentage }}%
                </div>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <div class="text-sm text-muted-foreground">Words Removed</div>
                <div class="text-2xl font-bold text-rose-500">{{ removedWords }}</div>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <div class="text-sm text-muted-foreground">Original Words</div>
                <div class="text-2xl font-bold">{{ originalWords }}</div>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <div class="text-sm text-muted-foreground">Cleaned Words</div>
                <div class="text-2xl font-bold text-emerald-500">{{ cleanedWords }}</div>
            </div>
        </div>

        <div v-if="formattedMetrics.length > 0" class="rounded-xl border bg-card p-4">
            <h2 class="mb-3 font-semibold">Processing Metrics</h2>
            <div class="flex flex-wrap gap-4">
                <div
                    v-for="metric in formattedMetrics"
                    :key="metric.name"
                    class="text-sm"
                >
                    <span class="text-muted-foreground">{{ metric.name }}:</span>
                    <span class="ml-1 font-medium">{{ metric.value }}</span>
                </div>
            </div>
        </div>

        <div
            v-if="audioSample.removals && audioSample.removals.length > 0"
            class="rounded-xl border bg-card p-4"
        >
            <h2 class="mb-3 font-semibold">What Was Removed</h2>
            <div class="flex flex-wrap gap-2">
                <span
                    v-for="removal in audioSample.removals"
                    :key="removal.type"
                    class="rounded-full bg-rose-100 px-3 py-1 text-sm text-rose-700 dark:bg-rose-900/30 dark:text-rose-400"
                >
                    {{ removal.type }}: {{ removal.count }}×
                </span>
            </div>
        </div>
    </div>

    <div
        v-if="canBeValidated || isValidated"
        id="validate-step"
        class="rounded-xl border bg-card p-4"
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold">Validate cleaned text</h2>
                <p class="text-sm text-muted-foreground">
                    Confirm this cleaned transcript is ready for benchmarking.
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    Summary: {{ cleanedWords }} cleaned words · {{ removedWords }} removed · {{ reductionPercentage }}% reduction
                </p>
                <p v-if="isEditing" class="mt-2 text-xs text-amber-600">
                    You have unsaved edits. Save or cancel before validating.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    v-if="canBeValidated"
                    @click="emit('validate')"
                    :disabled="validateProcessing || isEditing"
                    class="h-10 rounded-lg bg-emerald-600 px-4 font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                >
                    Validate Cleaned Text
                </button>
                <button
                    v-else-if="isValidated"
                    @click="emit('validate')"
                    :disabled="validateProcessing"
                    class="h-10 rounded-lg border border-border bg-muted/50 px-4 font-medium hover:bg-muted"
                >
                    Remove Benchmark Ready
                </button>
            </div>
        </div>
    </div>
</template>
