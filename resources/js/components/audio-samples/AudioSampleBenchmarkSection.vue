<script setup lang="ts">
import {
    DocumentTextIcon,
    ExclamationTriangleIcon,
    InformationCircleIcon,
    MicrophoneIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';
import { computed, ref } from 'vue';
import type { AsrProvider } from '@/types/audio-samples';
import type { TranscriptionWithStatus } from '@/types/transcriptions';

const props = defineProps<{
    audioSampleId: number;
    isValidated: boolean;
    showTranscriptionForm: boolean;
    showManualEntryForm: boolean;
    transcriptions: TranscriptionWithStatus[];
    asrProviders: Record<string, AsrProvider>;
    asrProviderOptions: { id: string; name: string; hasCredential: boolean }[];
    asrProviderModels: { id: string; name: string }[];
    loadingAsrModels: boolean;
    transcribeForm: any;
    manualTranscriptionForm: any;
    manualProviderSelection: string;
    manualProviderCustom: string;
    manualModelSelection: string;
    manualModelCustom: string;
    manualModelOptions: { id: string; name: string }[];
    isManualProviderCustom: boolean;
    isManualModelCustom: boolean;
    manualProviderValue: string;
    manualModelValue: string;
    formatErrorRate: (rate: number | null) => string;
    getWerColor: (wer: number | null) => string;
    getSourceColor: (source: string) => string;
}>();

const getStatusColor = (status: string) => {
    switch (status.toLowerCase()) {
        case 'completed':
            return 'text-green-600 bg-green-100 border-green-200';
        case 'processing':
            return 'text-blue-600 bg-blue-100 border-blue-200';
        case 'failed':
            return 'text-red-600 bg-red-100 border-red-200';
        default:
            return 'text-muted-foreground bg-muted/20 border-border';
    }
};

const emit = defineEmits<{
    (e: 'update:showTranscriptionForm', value: boolean): void;
    (e: 'update:showManualEntryForm', value: boolean): void;
    (e: 'update:manualProviderSelection', value: string): void;
    (e: 'update:manualProviderCustom', value: string): void;
    (e: 'update:manualModelSelection', value: string): void;
    (e: 'update:manualModelCustom', value: string): void;
    (e: 'submitTranscription'): void;
    (e: 'submitManualTranscription'): void;
    (e: 'deleteTranscription', id: number): void;
}>();

const showTranscriptionForm = computed({
    get: () => props.showTranscriptionForm,
    set: (value) => emit('update:showTranscriptionForm', value),
});

const showManualEntryForm = computed({
    get: () => props.showManualEntryForm,
    set: (value) => emit('update:showManualEntryForm', value),
});

const manualProviderSelection = computed({
    get: () => props.manualProviderSelection,
    set: (value) => emit('update:manualProviderSelection', value),
});

const manualProviderCustom = computed({
    get: () => props.manualProviderCustom,
    set: (value) => emit('update:manualProviderCustom', value),
});

const manualModelSelection = computed({
    get: () => props.manualModelSelection,
    set: (value) => emit('update:manualModelSelection', value),
});

const manualModelCustom = computed({
    get: () => props.manualModelCustom,
    set: (value) => emit('update:manualModelCustom', value),
});

const filterMode = ref<'all' | 'best' | 'generated' | 'manual'>('all');

const normalizeRate = (rate: number | null) => {
    if (rate === null) return null;
    return rate > 1 ? rate / 100 : rate;
};

const sortedTranscriptions = computed(() => {
    return [...props.transcriptions].sort((a, b) => {
        const aWer = normalizeRate(a.wer) ?? Number.POSITIVE_INFINITY;
        const bWer = normalizeRate(b.wer) ?? Number.POSITIVE_INFINITY;
        return aWer - bWer;
    });
});

const filteredTranscriptions = computed(() => {
    if (filterMode.value === 'generated') {
        return sortedTranscriptions.value.filter((t) => t.source === 'generated');
    }
    if (filterMode.value === 'manual') {
        return sortedTranscriptions.value.filter((t) => t.source === 'imported');
    }
    if (filterMode.value === 'best') {
        return sortedTranscriptions.value.slice(0, 5);
    }
    return sortedTranscriptions.value;
});

const summary = computed(() => {
    const rates = sortedTranscriptions.value
        .map((t) => normalizeRate(t.wer))
        .filter((value): value is number => value !== null);
    const cerRates = sortedTranscriptions.value
        .map((t) => normalizeRate(t.cer))
        .filter((value): value is number => value !== null);

    const bestWer = rates.length ? Math.min(...rates) : null;
    const avgWer = rates.length
        ? rates.reduce((sum, value) => sum + value, 0) / rates.length
        : null;
    const avgCer = cerRates.length
        ? cerRates.reduce((sum, value) => sum + value, 0) / cerRates.length
        : null;

    return {
        bestWer,
        avgWer,
        avgCer,
    };
});

const downloadBlob = (content: string, fileName: string, type: string) => {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    link.click();
    URL.revokeObjectURL(url);
};

const exportJson = () => {
    downloadBlob(
        JSON.stringify(sortedTranscriptions.value, null, 2),
        `audio-sample-${props.audioSampleId}-benchmark.json`,
        'application/json',
    );
};

const csvEscape = (value: string | number | null) => {
    if (value === null || value === undefined) return '';
    const stringValue = String(value);
    if (/[",\n]/.test(stringValue)) {
        return `"${stringValue.replace(/"/g, '""')}"`;
    }
    return stringValue;
};

const exportCsv = () => {
    const header = [
        'model',
        'version',
        'source',
        'wer',
        'cer',
        'substitutions',
        'insertions',
        'deletions',
        'notes',
    ];

    const rows = sortedTranscriptions.value.map((t) => [
        t.model_name,
        t.model_version ?? '',
        t.source,
        t.wer ?? '',
        t.cer ?? '',
        t.substitutions,
        t.insertions,
        t.deletions,
        t.notes ?? '',
    ]);

    const csv = [header, ...rows]
        .map((row) => row.map(csvEscape).join(','))
        .join('\n');

    downloadBlob(
        csv,
        `audio-sample-${props.audioSampleId}-benchmark.csv`,
        'text/csv',
    );
};

const goToTranscription = (id: number) => {
    window.location.href = `/audio-samples/${props.audioSampleId}/transcriptions/${id}`;
};
</script>

<template>
    <div v-if="isValidated" class="mt-6 space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="flex items-center gap-2 text-xl font-semibold">
                    <MicrophoneIcon class="h-5 w-5" />
                    Benchmark (ASR)
                </h2>
                <p class="text-sm text-muted-foreground">
                    Run ASR models or add manual results to compare accuracy.
                </p>
            </div>
            <div class="flex gap-2">
                <button
                    @click="showTranscriptionForm = !showTranscriptionForm"
                    class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    <MicrophoneIcon class="h-4 w-4" />
                    Run ASR
                </button>
            </div>
        </div>

        <div
            v-if="showTranscriptionForm"
            class="rounded-xl border bg-card p-6"
        >
            <h3 class="mb-4 font-semibold">Run ASR transcription</h3>
            <form
                @submit.prevent="emit('submitTranscription')"
                class="space-y-4"
            >
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">ASR Provider</label>
                        <select
                            v-model="transcribeForm.provider"
                            class="w-full rounded-lg border bg-background px-3 py-2"
                        >
                            <option
                                v-for="provider in asrProviderOptions"
                                :key="provider.id"
                                :value="provider.id"
                            >
                                {{ provider.name }}
                                <span v-if="!provider.hasCredential">(No API Key)</span>
                            </option>
                        </select>
                        <p
                            v-if="asrProviders[transcribeForm.provider]?.description"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ asrProviders[transcribeForm.provider].description }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Model</label>
                        <select
                            v-model="transcribeForm.model"
                            :disabled="loadingAsrModels"
                            class="w-full rounded-lg border bg-background px-3 py-2 disabled:opacity-50"
                        >
                            <option
                                v-for="model in asrProviderModels"
                                :key="model.id"
                                :value="model.id"
                            >
                                {{ model.name }}
                            </option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Notes (optional)</label>
                    <textarea
                        v-model="transcribeForm.notes"
                        rows="2"
                        class="w-full rounded-lg border bg-background px-3 py-2"
                        placeholder="Any notes about this transcription run..."
                    ></textarea>
                </div>

                <div
                    v-if="asrProviderOptions.find((p) => p.id === transcribeForm.provider && !p.hasCredential)"
                    class="flex items-center gap-2 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-200"
                >
                    <ExclamationTriangleIcon class="h-5 w-5" />
                    No API key configured for this provider. Please add credentials in Settings.
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        @click="showTranscriptionForm = false"
                        class="rounded-lg border px-4 py-2 font-medium hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="
                            transcribeForm.processing ||
                            !asrProviderOptions.find((p) => p.id === transcribeForm.provider)?.hasCredential
                        "
                        class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        <span v-if="transcribeForm.processing">Processing...</span>
                        <span v-else>Start transcription</span>
                    </button>
                </div>
            </form>
        </div>

        <details
            class="rounded-xl border bg-card"
            :open="showManualEntryForm"
            @toggle="showManualEntryForm = ($event.target as HTMLDetailsElement).open"
        >
            <summary class="flex cursor-pointer items-center gap-2 px-4 py-3 font-medium hover:bg-muted/50">
                Manual entry
            </summary>
            <div class="border-t px-4 py-5">
                <p class="mb-4 text-sm text-muted-foreground">
                    Manually enter transcription results from an external ASR system for benchmarking.
                </p>
                <form
                    @submit.prevent="emit('submitManualTranscription')"
                    class="space-y-4"
                >
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Provider *</label>
                            <select
                                v-model="manualProviderSelection"
                                required
                                class="w-full rounded-lg border bg-background px-3 py-2"
                            >
                                <option
                                    v-for="provider in asrProviderOptions"
                                    :key="provider.id"
                                    :value="provider.id"
                                >
                                    {{ provider.name }}
                                </option>
                                <option value="custom">Custom...</option>
                            </select>
                            <input
                                v-if="isManualProviderCustom"
                                v-model="manualProviderCustom"
                                type="text"
                                required
                                class="mt-2 w-full rounded-lg border bg-background px-3 py-2"
                                placeholder="e.g., google, azure, whisperx"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">Model *</label>
                            <select
                                v-model="manualModelSelection"
                                required
                                class="w-full rounded-lg border bg-background px-3 py-2"
                            >
                                <option
                                    v-for="model in manualModelOptions"
                                    :key="model.id"
                                    :value="model.id"
                                >
                                    {{ model.name }}
                                </option>
                                <option value="custom">Custom...</option>
                            </select>
                            <input
                                v-if="isManualModelCustom"
                                v-model="manualModelCustom"
                                type="text"
                                required
                                class="mt-2 w-full rounded-lg border bg-background px-3 py-2"
                                placeholder="e.g., whisper-large-v3, yiddish-libre"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Model Version (optional)</label>
                        <input
                            v-model="manualTranscriptionForm.model_version"
                            type="text"
                            class="w-full rounded-lg border bg-background px-3 py-2"
                            placeholder="e.g., v2, 2024-01"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Transcription Output *</label>
                        <textarea
                            v-model="manualTranscriptionForm.hypothesis_text"
                            required
                            rows="4"
                            dir="rtl"
                            class="w-full rounded-lg border bg-background px-3 py-2 font-mono"
                            placeholder="Paste the ASR output here..."
                        ></textarea>
                        <p class="mt-1 text-xs text-muted-foreground">
                            WER and CER will be calculated automatically against the reference text.
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Notes</label>
                        <textarea
                            v-model="manualTranscriptionForm.notes"
                            rows="2"
                            class="w-full rounded-lg border bg-background px-3 py-2"
                            placeholder="Configuration details, processing time, etc..."
                        ></textarea>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            @click="showManualEntryForm = false"
                            class="rounded-lg border px-4 py-2 font-medium hover:bg-muted"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="manualTranscriptionForm.processing || !manualProviderValue || !manualModelValue"
                            class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            <span v-if="manualTranscriptionForm.processing">Saving...</span>
                            <span v-else>Add entry</span>
                        </button>
                    </div>
                </form>
            </div>
        </details>

        <div v-if="transcriptions.length > 0" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-xs uppercase text-muted-foreground">Best WER</div>
                    <div class="text-2xl font-semibold">
                        {{ summary.bestWer !== null ? `${(summary.bestWer * 100).toFixed(2)}%` : 'N/A' }}
                    </div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-xs uppercase text-muted-foreground">Average WER</div>
                    <div class="text-2xl font-semibold">
                        {{ summary.avgWer !== null ? `${(summary.avgWer * 100).toFixed(2)}%` : 'N/A' }}
                    </div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-xs uppercase text-muted-foreground">Average CER</div>
                    <div class="text-2xl font-semibold">
                        {{ summary.avgCer !== null ? `${(summary.avgCer * 100).toFixed(2)}%` : 'N/A' }}
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="filterMode = 'all'"
                        :class="[
                            'rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                            filterMode === 'all' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                        ]"
                    >
                        All
                    </button>
                    <button
                        type="button"
                        @click="filterMode = 'best'"
                        :class="[
                            'rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                            filterMode === 'best' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                        ]"
                    >
                        Best 5
                    </button>
                    <button
                        type="button"
                        @click="filterMode = 'generated'"
                        :class="[
                            'rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                            filterMode === 'generated' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                        ]"
                    >
                        API
                    </button>
                    <button
                        type="button"
                        @click="filterMode = 'manual'"
                        :class="[
                            'rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                            filterMode === 'manual' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                        ]"
                    >
                        Manual
                    </button>
                </div>

                <details class="relative">
                    <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted">
                        Export
                    </summary>
                    <div class="absolute right-0 z-10 mt-2 w-40 rounded-lg border bg-popover p-1 shadow-lg">
                        <button
                            type="button"
                            @click="exportCsv"
                            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-muted"
                        >
                            Export CSV
                        </button>
                        <button
                            type="button"
                            @click="exportJson"
                            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-muted"
                        >
                            Export JSON
                        </button>
                    </div>
                </details>
            </div>

            <div class="overflow-hidden rounded-xl border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-muted/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-medium">Model</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Source</th>
                                <th class="px-4 py-3 text-center text-sm font-medium">
                                    <span class="inline-flex items-center gap-1">
                                        WER
                                        <InformationCircleIcon
                                            class="h-4 w-4 text-muted-foreground"
                                            v-tippy="'Word Error Rate: percent of words that were wrong. Lower is better.'"
                                        />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-center text-sm font-medium">
                                    <span class="inline-flex items-center gap-1">
                                        CER
                                        <InformationCircleIcon
                                            class="h-4 w-4 text-muted-foreground"
                                            v-tippy="'Character Error Rate: percent of characters that were wrong. Lower is better.'"
                                        />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-center text-sm font-medium">
                                    <span class="inline-flex items-center gap-1">
                                        Status
                                        <InformationCircleIcon
                                            class="h-4 w-4 text-muted-foreground"
                                            v-tippy="'Breakdown of substitutions, insertions, and deletions in the transcription.'"
                                        />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-center text-sm font-medium">Errors</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Notes</th>
                                <th class="px-4 py-3 text-right text-sm font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="transcription in filteredTranscriptions"
                                :key="transcription.id"
                                class="cursor-pointer hover:bg-muted/30"
                                role="link"
                                tabindex="0"
                                @click="goToTranscription(transcription.id)"
                                @keydown.enter.prevent="goToTranscription(transcription.id)"
                                @keydown.space.prevent="goToTranscription(transcription.id)"
                            >
                                <td class="px-4 py-3">
                                    <div class="font-medium">
                                        {{ transcription.model_name }}
                                    </div>
                                    <div v-if="transcription.model_version" class="text-xs text-muted-foreground">
                                        v{{ transcription.model_version }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="[
                                            'rounded-full px-2 py-0.5 text-xs font-medium',
                                            getSourceColor(transcription.source),
                                        ]"
                                    >
                                        {{ transcription.source === 'generated' ? 'API' : 'Manual' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span
                                        :class="['font-mono font-semibold', getWerColor(transcription.wer)]"
                                    >
                                        {{ formatErrorRate(transcription.wer) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="font-mono text-muted-foreground">
                                        {{ formatErrorRate(transcription.cer) }}
                                    </span>
                                </td>
                                <td class="">
                                    <span
                                        class="inline-block rounded-full border px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="getStatusColor(transcription.status)">
                                        {{ transcription.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="text-xs text-muted-foreground">
                                        <span title="Substitutions">S:{{ transcription.substitutions }}</span>
                                        <span class="mx-1">·</span>
                                        <span title="Insertions">I:{{ transcription.insertions }}</span>
                                        <span class="mx-1">·</span>
                                        <span title="Deletions">D:{{ transcription.deletions }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="transcription.notes"
                                        class="block max-w-48 truncate text-sm text-muted-foreground"
                                        :title="transcription.notes"
                                    >
                                        {{ transcription.notes }}
                                    </span>
                                    <span v-else class="text-muted-foreground/50">—</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        @click.stop="emit('deleteTranscription', transcription.id)"
                                        class="inline-flex items-center gap-1 rounded px-2 py-1 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
                                        title="Delete transcription"
                                    >
                                        <TrashIcon class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div
            v-else
            class="rounded-xl border border-dashed bg-card p-8 text-center"
        >
            <MicrophoneIcon class="mx-auto h-12 w-12 text-muted-foreground/50" />
            <h3 class="mt-4 font-semibold">No Transcriptions Yet</h3>
            <p class="mt-2 text-sm text-muted-foreground">
                Run an ASR model or add a manual entry to start benchmarking this sample.
            </p>
        </div>
    </div>
</template>
