<script setup lang="ts">
import AlertError from '@/components/AlertError.vue';
import AudioPlayer from '@/components/AudioPlayer.vue';
import AudioSampleBenchmarkSection from '@/components/audio-samples/AudioSampleBenchmarkSection.vue';
import AudioSampleContextPanel from '@/components/audio-samples/AudioSampleContextPanel.vue';
import LinkTranscriptionModal from '@/components/transcriptions/LinkTranscriptionModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type {
    AsrProvider,
    AudioMedia,
    AudioSampleDetail,
} from '@/types/audio-samples';
import type { BaseTranscription } from '@/types/transcriptions';
import {
    MusicalNoteIcon,
    DocumentTextIcon,
    LinkIcon,
    CheckCircleIcon,
    XCircleIcon,
    PlusIcon,
    SparklesIcon,
    ArrowTopRightOnSquareIcon,
} from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    audioSample: AudioSampleDetail;
    audioMedia: AudioMedia | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Audio Samples', href: '/audio-samples' },
    {
        title: props.audioSample.name,
        href: `/audio-samples/${props.audioSample.id}`,
    },
];

// Audio helpers
const hasAudio = computed(() => !!props.audioMedia);

// Status helpers - new simplified statuses
const isDraft = computed(() => props.audioSample.status === 'draft');
const isPendingBase = computed(() => props.audioSample.status === 'pending_base');
const isUnclean = computed(() => props.audioSample.status === 'unclean');
const isReady = computed(() => props.audioSample.status === 'ready');
const isBenchmarked = computed(() => props.audioSample.status === 'benchmarked');
const isFailed = computed(() => props.audioSample.status === 'failed');

// Base transcription helpers
const baseTranscription = computed(() => props.audioSample.base_transcription);
const hasBaseTranscription = computed(() => !!baseTranscription.value);
const baseIsValidated = computed(() => !!baseTranscription.value?.validated_at);
const baseIsCleaned = computed(() => !!baseTranscription.value?.text_clean);

// ASR transcriptions
const asrTranscriptions = computed(() => props.audioSample.asr_transcriptions || []);

// Can run ASR benchmarks?
const canBenchmark = computed(() => 
    hasAudio.value && hasBaseTranscription.value && baseIsValidated.value
);

// Link modal state
const showLinkModal = ref(false);

const handleLinked = (transcription: BaseTranscription) => {
    showLinkModal.value = false;
};

// Unlink base transcription
const unlinkBaseTranscription = () => {
    if (!baseTranscription.value) return;
    if (!confirm('Unlink this base transcription from the audio sample?')) return;
    
    router.delete(`/transcriptions/${baseTranscription.value.id}/link`, {
        preserveScroll: true,
    });
};

// Audio upload form
const audioForm = useForm({
    audio: null as File | null,
});

const uploadAudio = () => {
    if (!audioForm.audio) return;
    audioForm.post(`/audio-samples/${props.audioSample.id}/audio`, {
        preserveScroll: true,
        onSuccess: () => {
            audioForm.reset();
        },
    });
};

// Delete audio sample
const deleteAudioSample = () => {
    if (confirm('Are you sure you want to delete this audio sample?')) {
        router.delete(`/audio-samples/${props.audioSample.id}`);
    }
};

// ==========================================
// ASR Transcription Section
// ==========================================

const showTranscriptionForm = ref(false);
const showManualEntryForm = ref(false);

// ASR providers state
const asrProviders = ref<Record<string, AsrProvider>>({});
const loadingAsrModels = ref(false);
const asrProviderModels = ref<{ id: string; name: string }[]>([]);

// ASR transcription form
const transcribeForm = useForm({
    provider: 'yiddishlabs',
    model: 'yiddish-libre',
    notes: '',
});

// Manual benchmark entry form
const manualTranscriptionForm = useForm({
    provider: '',
    model: '',
    model_version: '',
    hypothesis_text: '',
    notes: '',
});

const manualProviderSelection = ref('');
const manualProviderCustom = ref('');
const manualModelSelection = ref('');
const manualModelCustom = ref('');

const isManualProviderCustom = computed(
    () => manualProviderSelection.value === 'custom',
);

const manualProviderValue = computed(() =>
    isManualProviderCustom.value
        ? manualProviderCustom.value.trim()
        : manualProviderSelection.value,
);

const manualModelOptions = computed(
    () => asrProviders.value[manualProviderSelection.value]?.models ?? [],
);

const isManualModelCustom = computed(
    () =>
        isManualProviderCustom.value ||
        manualModelSelection.value === 'custom' ||
        manualModelOptions.value.length === 0,
);

const manualModelValue = computed(() =>
    isManualModelCustom.value
        ? manualModelCustom.value.trim()
        : manualModelSelection.value,
);

// ASR provider options
const asrProviderOptions = computed(() =>
    Object.entries(asrProviders.value).map(([key, value]) => ({
        id: key,
        name: value.name,
        hasCredential: value.has_credential,
    })),
);

// Fetch ASR providers
const fetchAsrProviders = async () => {
    try {
        const response = await fetch('/api/asr/providers');
        const data = await response.json();
        asrProviders.value = data;

        if (data[transcribeForm.provider]) {
            asrProviderModels.value = data[transcribeForm.provider].models;
        }
    } catch (error) {
        console.error('Failed to fetch ASR providers:', error);
    }
};

// Fetch ASR models for selected provider
const fetchAsrModelsForProvider = async (provider: string) => {
    loadingAsrModels.value = true;
    try {
        if (asrProviders.value[provider]) {
            asrProviderModels.value = asrProviders.value[provider].models;
            const modelIds = asrProviderModels.value.map((m) => m.id);
            if (!modelIds.includes(transcribeForm.model)) {
                transcribeForm.model = asrProviders.value[provider].default_model;
            }
        }
    } finally {
        loadingAsrModels.value = false;
    }
};

// Watch ASR provider changes
watch(
    () => transcribeForm.provider,
    (newProvider) => {
        fetchAsrModelsForProvider(newProvider);
    },
);

watch(
    () => asrProviders.value,
    (providers) => {
        if (!manualProviderSelection.value) {
            const firstProvider = Object.keys(providers)[0];
            manualProviderSelection.value = firstProvider || 'custom';
        }
    },
    { deep: true },
);

watch(
    () => manualProviderSelection.value,
    (provider) => {
        if (provider === 'custom') {
            manualModelSelection.value = 'custom';
            return;
        }
        const models = asrProviders.value[provider]?.models ?? [];
        if (models.length === 0) {
            manualModelSelection.value = 'custom';
            return;
        }
        if (!models.some((model) => model.id === manualModelSelection.value)) {
            manualModelSelection.value = models[0].id;
        }
    },
);

// Fetch ASR providers on mount if ready for benchmarking
onMounted(() => {
    if (canBenchmark.value) {
        fetchAsrProviders();
    }
});

// Watch for status change to ready
watch(canBenchmark, (ready) => {
    if (ready && Object.keys(asrProviders.value).length === 0) {
        fetchAsrProviders();
    }
});

// Submit ASR transcription
const submitTranscription = () => {
    transcribeForm.post(`/audio-samples/${props.audioSample.id}/transcribe`, {
        preserveScroll: true,
        onSuccess: () => {
            showTranscriptionForm.value = false;
            transcribeForm.reset('notes');
        },
    });
};

// Submit manual benchmark entry
const submitManualTranscription = () => {
    manualTranscriptionForm.provider = manualProviderValue.value;
    manualTranscriptionForm.model = manualModelValue.value;
    manualTranscriptionForm.post(
        `/audio-samples/${props.audioSample.id}/transcriptions`,
        {
            preserveScroll: true,
            onSuccess: () => {
                showManualEntryForm.value = false;
                manualTranscriptionForm.reset();
                manualProviderCustom.value = '';
                manualModelCustom.value = '';
            },
        },
    );
};

// Delete transcription
const deleteTranscription = (transcriptionId: number) => {
    if (confirm('Are you sure you want to delete this transcription?')) {
        router.delete(
            `/audio-samples/${props.audioSample.id}/transcriptions/${transcriptionId}`,
            {
                preserveScroll: true,
            },
        );
    }
};

const normalizeErrorRate = (rate: number | null): number | null => {
    if (rate === null) return null;
    return rate > 1 ? rate / 100 : rate;
};

const formatErrorRate = (rate: number | null): string => {
    if (rate === null) return 'N/A';
    const percent = rate > 1 ? rate : rate * 100;
    return `${percent.toFixed(2)}%`;
};

const getWerColor = (wer: number | null): string => {
    const normalized = normalizeErrorRate(wer);
    if (normalized === null) return 'text-muted-foreground';
    if (normalized <= 0.1) return 'text-emerald-600 dark:text-emerald-400';
    if (normalized <= 0.2) return 'text-green-600 dark:text-green-400';
    if (normalized <= 0.3) return 'text-yellow-600 dark:text-yellow-400';
    if (normalized <= 0.5) return 'text-orange-600 dark:text-orange-400';
    return 'text-red-600 dark:text-red-400';
};

const getSourceColor = (source: string): string => {
    if (source === 'generated') {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
    }
    return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400';
};

const formatDate = (dateString: string | null) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};
</script>

<template>
    <Head :title="audioSample.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            
            <!-- Error Alert -->
            <div v-if="isFailed && audioSample.error_message">
                <AlertError
                    :errors="[audioSample.error_message]"
                    title="Processing Failed"
                />
            </div>

            <!-- Context Panel (Header with name, status, actions) -->
            <AudioSampleContextPanel
                :audio-sample="audioSample"
                :audio-media="audioMedia"
                :has-audio="hasAudio"
                :has-raw-text="hasBaseTranscription"
                :has-cleaned-text="baseIsCleaned"
                @delete="deleteAudioSample"
            />

            <div class="space-y-6">
                <!-- Audio Player Section -->
                <AudioPlayer
                    v-if="hasAudio && audioMedia?.url"
                    :src="audioMedia.url"
                    :name="audioMedia.name"
                    :file-size="audioMedia.size"
                />

                <!-- Missing Audio: Upload Required -->
                <div
                    v-else
                    class="rounded-xl border-2 border-dashed border-rose-300 bg-rose-50 p-4 sm:p-6 dark:border-rose-700 dark:bg-rose-900/20"
                >
                    <div class="flex items-start gap-4">
                        <MusicalNoteIcon class="h-8 w-8 shrink-0 text-rose-600 dark:text-rose-400" />
                        <div class="flex-1">
                            <h2 class="text-lg font-semibold text-rose-800 dark:text-rose-300">
                                Audio File Missing
                            </h2>
                            <p class="mb-4 text-sm text-rose-700 dark:text-rose-400">
                                This audio sample is missing its audio file.
                                Upload an audio file to enable ASR benchmarking.
                            </p>
                            <form
                                @submit.prevent="uploadAudio"
                                class="flex flex-col gap-3 sm:flex-row sm:items-end"
                            >
                                <div class="flex-1">
                                    <label class="mb-1 block text-sm font-medium">Audio File</label>
                                    <input
                                        type="file"
                                        accept=".mp3,.wav,.ogg,.m4a,.flac"
                                        @change="(e: any) => (audioForm.audio = e.target.files[0])"
                                        class="block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    :disabled="!audioForm.audio || audioForm.processing"
                                    class="rounded-lg bg-rose-600 px-4 py-2 font-medium text-white hover:bg-rose-700 disabled:opacity-50"
                                >
                                    {{ audioForm.processing ? 'Uploading...' : 'Upload Audio' }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Base Transcription Section -->
                <div class="rounded-xl border bg-card">
                    <div class="flex items-center justify-between border-b px-6 py-4">
                        <h2 class="flex items-center gap-2 text-lg font-semibold">
                            <DocumentTextIcon class="h-5 w-5" />
                            Base Transcription
                        </h2>
                        <div v-if="!hasBaseTranscription" class="flex gap-2">
                            <Link
                                href="/transcriptions/create"
                                class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted"
                            >
                                <PlusIcon class="h-4 w-4" />
                                Create New
                            </Link>
                            <button
                                @click="showLinkModal = true"
                                class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                            >
                                <LinkIcon class="h-4 w-4" />
                                Link Existing
                            </button>
                        </div>
                    </div>

                    <!-- No Base Transcription -->
                    <div v-if="!hasBaseTranscription" class="p-6 text-center">
                        <DocumentTextIcon class="mx-auto h-12 w-12 text-muted-foreground/50" />
                        <p class="mt-2 text-sm text-muted-foreground">
                            No base transcription linked to this audio sample.
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Link an existing transcription or create a new one to enable cleaning and benchmarking.
                        </p>
                    </div>

                    <!-- Has Base Transcription -->
                    <div v-else class="p-6">
                        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-2">
                                    <Link
                                        :href="`/transcriptions/${baseTranscription!.id}`"
                                        class="text-lg font-medium text-primary hover:underline"
                                    >
                                        {{ baseTranscription!.name || `Transcription #${baseTranscription!.id}` }}
                                    </Link>
                                    <span
                                        v-if="baseIsValidated"
                                        class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400"
                                    >
                                        <CheckCircleIcon class="h-3 w-3" />
                                        Validated
                                    </span>
                                    <span
                                        v-else-if="baseIsCleaned"
                                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-400"
                                    >
                                        Needs Validation
                                    </span>
                                    <span
                                        v-else
                                        class="rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400"
                                    >
                                        Needs Cleaning
                                    </span>
                                </div>

                                <!-- Clean rate and preview -->
                                <div v-if="baseIsCleaned" class="space-y-2">
                                    <div class="flex items-center gap-4 text-sm text-muted-foreground">
                                        <span v-if="baseTranscription!.clean_rate !== null" class="font-mono">
                                            {{ baseTranscription!.clean_rate }}% clean
                                        </span>
                                        <span>Created {{ formatDate(baseTranscription!.created_at) }}</span>
                                    </div>
                                    <p class="text-sm line-clamp-3" dir="auto">
                                        {{ baseTranscription!.text_clean?.slice(0, 300) }}{{ (baseTranscription!.text_clean?.length || 0) > 300 ? '...' : '' }}
                                    </p>
                                </div>
                                <div v-else class="text-sm text-muted-foreground">
                                    <p>This transcription needs to be cleaned before benchmarking.</p>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2 shrink-0">
                                <Link
                                    :href="`/transcriptions/${baseTranscription!.id}`"
                                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                                >
                                    <ArrowTopRightOnSquareIcon class="h-4 w-4" />
                                    {{ baseIsValidated ? 'View' : baseIsCleaned ? 'Review & Validate' : 'Clean' }}
                                </Link>
                                <button
                                    @click="unlinkBaseTranscription"
                                    class="inline-flex items-center gap-2 justify-center rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted"
                                >
                                    <XCircleIcon class="h-4 w-4" />
                                    Unlink
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Replace Audio option (if sample has audio already) -->
                <details v-if="hasAudio" class="rounded-xl border bg-card">
                    <summary class="flex cursor-pointer items-center gap-2 px-4 py-3 font-medium hover:bg-muted/50">
                        <MusicalNoteIcon class="h-4 w-4" />
                        Replace Audio File
                    </summary>
                    <div class="px-4 pb-4">
                        <p class="mb-4 text-sm text-muted-foreground">
                            Upload a new audio file to replace the current one.
                        </p>
                        <form @submit.prevent="uploadAudio" class="space-y-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium">New Audio File</label>
                                <input
                                    type="file"
                                    accept=".mp3,.wav,.ogg,.m4a,.flac"
                                    @change="(e: any) => (audioForm.audio = e.target.files[0])"
                                    class="block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                />
                            </div>
                            <button
                                type="submit"
                                :disabled="!audioForm.audio || audioForm.processing"
                                class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                            >
                                {{ audioForm.processing ? 'Uploading...' : 'Replace Audio' }}
                            </button>
                        </form>
                    </div>
                </details>

                <!-- ASR Benchmark Section -->
                <div id="benchmark-step">
                    <AudioSampleBenchmarkSection
                        :audio-sample-id="audioSample.id"
                        :is-validated="baseIsValidated"
                        :show-transcription-form="showTranscriptionForm"
                        :show-manual-entry-form="showManualEntryForm"
                        :transcriptions="asrTranscriptions"
                        :asr-providers="asrProviders"
                        :asr-provider-options="asrProviderOptions"
                        :asr-provider-models="asrProviderModels"
                        :loading-asr-models="loadingAsrModels"
                        :transcribe-form="transcribeForm"
                        :manual-transcription-form="manualTranscriptionForm"
                        :manual-provider-selection="manualProviderSelection"
                        :manual-provider-custom="manualProviderCustom"
                        :manual-model-selection="manualModelSelection"
                        :manual-model-custom="manualModelCustom"
                        :manual-model-options="manualModelOptions"
                        :is-manual-provider-custom="isManualProviderCustom"
                        :is-manual-model-custom="isManualModelCustom"
                        :manual-provider-value="manualProviderValue"
                        :manual-model-value="manualModelValue"
                        :format-error-rate="formatErrorRate"
                        :get-wer-color="getWerColor"
                        :get-source-color="getSourceColor"
                        @update:show-transcription-form="(value) => (showTranscriptionForm = value)"
                        @update:show-manual-entry-form="(value) => (showManualEntryForm = value)"
                        @update:manual-provider-selection="(value) => (manualProviderSelection = value)"
                        @update:manual-provider-custom="(value) => (manualProviderCustom = value)"
                        @update:manual-model-selection="(value) => (manualModelSelection = value)"
                        @update:manual-model-custom="(value) => (manualModelCustom = value)"
                        @submit-transcription="submitTranscription"
                        @submit-manual-transcription="submitManualTranscription"
                        @delete-transcription="deleteTranscription"
                    />
                </div>
            </div>
        </div>

        <!-- Link Transcription Modal -->
        <LinkTranscriptionModal
            :is-open="showLinkModal"
            :audio-sample-id="audioSample.id"
            :audio-sample-name="audioSample.name"
            @close="showLinkModal = false"
            @linked="handleLinked"
        />
    </AppLayout>
</template>
