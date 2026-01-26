<script setup lang="ts">
import {
    ArrowPathIcon,
    ArrowTopRightOnSquareIcon,
    ChartBarIcon,
    CheckCircleIcon,
    CloudArrowUpIcon,
    DocumentTextIcon,
    LinkIcon,
    MusicalNoteIcon,
    PlayIcon,
    PlusIcon,
    SparklesIcon,
    TrashIcon,
    UserIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline';
import { CheckIcon } from '@heroicons/vue/24/solid';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

import { formatErrorRate, getWerColor } from '@/lib/asrMetrics';
import { formatCreatedBy } from '@/lib/createdBy';
import { formatDate } from '@/lib/date';
import { decodeHtmlEntities } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type {
    AsrProvider,
    AudioMedia,
    AudioSampleDetail,
} from '@/types/audio-samples';
import type { BaseTranscription } from '@/types/transcriptions';

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
const isFailed = computed(() => props.audioSample.status === 'failed');

// Base transcription helpers
const baseTranscription = computed(() => props.audioSample.base_transcription);
const hasBaseTranscription = computed(() => !!baseTranscription.value);
const baseIsValidated = computed(() => !!baseTranscription.value?.validated_at);
const baseIsCleaned = computed(() => !!baseTranscription.value?.text_clean);

const decodedBaseCleanText = computed(() =>
    baseTranscription.value?.text_clean
        ? decodeHtmlEntities(baseTranscription.value.text_clean)
        : '',
);

// ASR transcriptions
const asrTranscriptions = computed(
    () => props.audioSample.asr_transcriptions || [],
);
const failedAsrTranscriptions = computed(() =>
    asrTranscriptions.value.filter(
        (transcription) =>
            transcription.status === 'failed' && transcription.error_message,
    ),
);

// Can run ASR benchmarks?
const canBenchmark = computed(
    () => hasAudio.value && hasBaseTranscription.value && baseIsValidated.value,
);

// Link modal state
const showLinkModal = ref(false);

const handleLinked = (transcription: BaseTranscription) => {
    showLinkModal.value = false;
};

// Unlink base transcription
const unlinkBaseTranscription = () => {
    if (!baseTranscription.value) return;
    if (!confirm('Unlink this base transcription from the audio sample?'))
        return;

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
                transcribeForm.model =
                    asrProviders.value[provider].default_model;
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

const getSourceColor = (source: string): string => {
    if (source === 'generated') {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
    }
    return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400';
};

// Workflow step helpers
const workflowStep = computed(() => {
    if (!hasAudio.value) return 0;
    if (!hasBaseTranscription.value) return 1;
    if (!baseIsCleaned.value) return 2;
    if (!baseIsValidated.value) return 3;
    return 4;
});

const steps = [
    { name: 'Audio', description: 'Upload audio file' },
    { name: 'Transcript', description: 'Link transcription' },
    { name: 'Clean', description: 'Clean the text' },
    { name: 'Validate', description: 'Review & approve' },
    { name: 'Benchmark', description: 'Run ASR tests' },
];
</script>

<template>

    <Head :title="audioSample.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div>
            <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
                <!-- Error Alert -->
                <div v-if="isFailed && audioSample.error_message" class="mb-6">
                    <AlertError :errors="[audioSample.error_message]" title="Processing Failed" />
                </div>

                <div v-else-if="failedAsrTranscriptions.length" class="mb-6">
                    <AlertError :errors="failedAsrTranscriptions.map((t) => t.error_message!)
                        " title="ASR Transcription Failed" />
                </div>

                <!-- Hero Header -->
                <div class="mb-8">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="space-y-3">
                            <div class="flex items-center gap-4">
                                <div
                                    class="shadow-glow flex h-14 w-14 items-center justify-center rounded-2xl bg-primary">
                                    <MusicalNoteIcon class="h-7 w-7 text-white" />
                                </div>
                                <div>
                                    <h1 class="text-3xl font-bold tracking-tight text-foreground">
                                        {{ audioSample.name }}
                                    </h1>
                                    <div class="flex items-center justify-start gap-2">
                                        <p class="text-sm text-muted-foreground">
                                             <TimeAgo :value="audioSample.created_at" />
                                        </p>
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
                                            <UserIcon class="h-3.5 w-3.5" />
                                            {{
        
                                                formatCreatedBy(
                                                    audioSample.user,
                                                    undefined,
                                                )
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button @click="deleteAudioSample"
                            class="inline-flex items-center gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm font-medium text-red-400 transition-all hover:border-red-500/50 hover:bg-red-500/20">
                            <TrashIcon class="h-4 w-4" />
                            Delete
                        </button>
                    </div>
                </div>

                <!-- Progress Steps -->
                <div class="mb-8 overflow-hidden rounded-xl border border-border bg-card p-6">
                    <Stepper :default-value="workflowStep + 1" :linear="false"
                        class="flex w-full items-start justify-between gap-2">
                        <StepperItem v-for="(step, stepIdx) in steps" :key="step.name" :step="stepIdx + 1"
                            class="relative flex flex-1 flex-col items-center gap-2">
                            <StepperSeparator v-if="stepIdx !== steps.length - 1"
                                class="absolute top-5 right-[calc(-50%+24px)] left-[calc(50%+24px)] block h-0.5 shrink-0 rounded-full group-data-[state=completed]:bg-primary" />
                            <StepperTrigger as-child>
                                <StepperIndicator
                                    class="group-data-[state=completed]:shadow-glow-sm z-10 h-10 w-10 rounded-full border-2 text-sm font-semibold transition-all duration-300 group-data-[state=active]:border-primary group-data-[state=active]:bg-primary/20 group-data-[state=active]:text-primary group-data-[state=completed]:border-primary group-data-[state=completed]:bg-primary group-data-[state=completed]:text-white group-data-[state=inactive]:border-border group-data-[state=inactive]:bg-muted group-data-[state=inactive]:text-muted-foreground">
                                    <CheckIcon v-if="stepIdx < workflowStep" class="h-5 w-5" />
                                    <span v-else>{{ stepIdx + 1 }}</span>
                                </StepperIndicator>
                            </StepperTrigger>
                            <div class="mt-1 flex flex-col items-center text-center">
                                <StepperTitle
                                    class="text-xs font-semibold group-data-[state=active]:text-foreground group-data-[state=completed]:text-foreground group-data-[state=inactive]:text-muted-foreground">
                                    {{ step.name }}
                                </StepperTitle>
                                <StepperDescription class="hidden text-xs sm:block">
                                    {{ step.description }}
                                </StepperDescription>
                            </div>
                        </StepperItem>
                    </Stepper>
                </div>

                <!-- Main Content Grid -->
                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Left Column: Audio & Transcript -->
                    <div class="space-y-6 lg:col-span-2">
                        <!-- Audio Card -->
                        <div
                            class="overflow-hidden rounded-xl border border-border bg-card transition-all hover:border-primary/50">
                            <div class="border-b border-border px-6 py-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="bg-success/15 flex h-10 w-10 items-center justify-center rounded-xl">
                                            <PlayIcon class="text-success h-5 w-5" />
                                        </div>
                                        <div>
                                            <h2 class="font-semibold text-foreground">
                                                Audio File
                                            </h2>
                                            <p class="text-xs text-muted-foreground">
                                                {{
                                                    hasAudio
                                                        ? audioMedia?.name
                                                        : 'No file uploaded'
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium"
                                        :class="hasAudio
                                                ? 'bg-success/15 text-success'
                                                : 'bg-warning/15 text-warning animate-pulse'
                                            ">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="hasAudio
                                                ? 'bg-success'
                                                : 'bg-warning'
                                            " />
                                        {{ hasAudio ? 'Uploaded' : 'Missing' }}
                                    </span>
                                </div>
                            </div>

                            <div class="p-6">
                                <AudioPlayer v-if="hasAudio && audioMedia?.url" :src="audioMedia.url"
                                    :name="audioMedia.name" :file-size="audioMedia.size" />

                                <div v-else class="py-8 text-center">
                                    <div
                                        class="bg-warning/15 mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl">
                                        <CloudArrowUpIcon class="text-warning h-8 w-8" />
                                    </div>
                                    <h3 class="mb-2 font-semibold text-foreground">
                                        Upload Audio File
                                    </h3>
                                    <p class="mb-6 text-sm text-muted-foreground">
                                        Drag and drop or click to upload your
                                        audio file
                                    </p>
                                    <form @submit.prevent="uploadAudio" class="flex flex-col items-center gap-4">
                                        <label class="group relative cursor-pointer">
                                            <input type="file" accept=".mp3,.wav,.ogg,.m4a,.flac" @change="
                                                (e: any) =>
                                                (audioForm.audio =
                                                    e.target.files[0])
                                            " class="sr-only" />
                                            <div
                                                class="flex items-center gap-2 rounded-lg border-2 border-dashed border-border bg-muted px-6 py-3 transition-all group-hover:border-primary group-hover:bg-primary/10">
                                                <MusicalNoteIcon
                                                    class="h-5 w-5 text-muted-foreground group-hover:text-primary" />
                                                <span
                                                    class="text-sm font-medium text-muted-foreground group-hover:text-primary">
                                                    {{
                                                        audioForm.audio
                                                            ? audioForm.audio
                                                                .name
                                                            : 'Choose file...'
                                                    }}
                                                </span>
                                            </div>
                                        </label>
                                        <button v-if="audioForm.audio" type="submit" :disabled="audioForm.processing"
                                            class="hover:shadow-glow-sm inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-3 font-medium text-primary-foreground transition-all hover:bg-primary/90 disabled:opacity-50">
                                            <ArrowPathIcon v-if="audioForm.processing" class="h-4 w-4 animate-spin" />
                                            {{
                                                audioForm.processing
                                                    ? 'Uploading...'
                                                    : 'Upload Audio'
                                            }}
                                        </button>
                                    </form>
                                </div>

                                <!-- Replace Audio (collapsed) -->
                                <details v-if="hasAudio" class="mt-4 rounded-lg border border-border">
                                    <summary
                                        class="cursor-pointer px-4 py-3 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground">
                                        Replace audio file
                                    </summary>
                                    <div class="border-t border-border p-4">
                                        <form @submit.prevent="uploadAudio"
                                            class="flex flex-col gap-4 sm:flex-row sm:items-end">
                                            <label class="flex-1">
                                                <input type="file" accept=".mp3,.wav,.ogg,.m4a,.flac" @change="
                                                    (e: any) =>
                                                    (audioForm.audio =
                                                        e.target.files[0])
                                                "
                                                    class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-primary/15 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary hover:file:bg-primary/25" />
                                            </label>
                                            <button type="submit" :disabled="!audioForm.audio ||
                                                audioForm.processing
                                                "
                                                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                                                Replace
                                            </button>
                                        </form>
                                    </div>
                                </details>
                            </div>
                        </div>

                        <!-- Base Transcription Card -->
                        <div
                            class="overflow-hidden rounded-xl border border-border bg-card transition-all hover:border-primary/50">
                            <div class="border-b border-border px-6 py-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/15">
                                            <DocumentTextIcon class="h-5 w-5 text-primary" />
                                        </div>
                                        <div>
                                            <h2 class="font-semibold text-foreground">
                                                Base Transcription
                                            </h2>
                                            <p class="text-xs text-muted-foreground">
                                                Reference text for benchmarking
                                            </p>
                                        </div>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium"
                                        :class="baseIsValidated
                                                ? 'bg-success/15 text-success'
                                                : baseIsCleaned
                                                    ? 'bg-warning/15 text-warning'
                                                    : hasBaseTranscription
                                                        ? 'bg-secondary/15 text-secondary'
                                                        : 'bg-muted text-muted-foreground'
                                            ">
                                        <span class="h-1.5 w-1.5 rounded-full" :class="baseIsValidated
                                                ? 'bg-success'
                                                : baseIsCleaned
                                                    ? 'bg-warning'
                                                    : hasBaseTranscription
                                                        ? 'bg-secondary'
                                                        : 'bg-muted-foreground'
                                            " />
                                        {{
                                            baseIsValidated
                                                ? 'Validated'
                                                : baseIsCleaned
                                                    ? 'Needs Review'
                                                    : hasBaseTranscription
                                                        ? 'Needs Cleaning'
                                                        : 'Not Linked'
                                        }}
                                    </span>
                                </div>
                            </div>

                            <div class="p-6">
                                <!-- No Base Transcription -->
                                <div v-if="!hasBaseTranscription" class="py-8 text-center">
                                    <div
                                        class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/15">
                                        <LinkIcon class="h-8 w-8 text-primary" />
                                    </div>
                                    <h3 class="mb-2 font-semibold text-foreground">
                                        Link a Transcription
                                    </h3>
                                    <p class="mb-6 text-sm text-muted-foreground">
                                        Connect an existing transcription or
                                        create a new one
                                    </p>
                                    <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                                        <button @click="showLinkModal = true"
                                            class="hover:shadow-glow-sm inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-3 font-medium text-primary-foreground transition-all hover:bg-primary/90">
                                            <LinkIcon class="h-4 w-4" />
                                            Link Existing
                                        </button>
                                        <Link href="/transcriptions/create"
                                            class="inline-flex items-center gap-2 rounded-lg border border-secondary px-6 py-3 font-medium text-secondary transition-all hover:bg-secondary/10">
                                            <PlusIcon class="h-4 w-4" />
                                            Create New
                                        </Link>
                                    </div>
                                </div>

                                <!-- Has Base Transcription -->
                                <div v-else>
                                    <div class="mb-4 flex items-start justify-between">
                                        <div>
                                            <Link :href="`/transcriptions/${baseTranscription!.id}`"
                                                class="text-lg font-semibold text-foreground transition-colors hover:text-primary">
                                                {{
                                                    baseTranscription!.name ||
                                                    `Transcription #${baseTranscription!.id}`
                                                }}
                                            </Link>
                                            <p class="mt-1 text-sm text-muted-foreground">
                                                <span v-if="
                                                    baseTranscription!
                                                        .clean_rate !== null
                                                " class="text-success mr-3 font-mono">
                                                    {{
                                                        baseTranscription!
                                                            .clean_rate
                                                    }}% clean
                                                </span>
                                                Created
                                                {{
                                                    formatDate(
                                                        baseTranscription!
                                                            .created_at,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                        <button @click="unlinkBaseTranscription"
                                            class="rounded-lg p-2 text-muted-foreground transition-colors hover:bg-red-500/10 hover:text-red-400"
                                            title="Unlink transcription">
                                            <XCircleIcon class="h-5 w-5" />
                                        </button>
                                    </div>

                                    <!-- Text Preview -->
                                    <div v-if="baseIsCleaned" class="mb-4 rounded-lg bg-muted p-4">
                                        <p class="line-clamp-4 text-sm leading-relaxed text-foreground/80" dir="auto">
                                            {{
                                                decodedBaseCleanText.slice(
                                                    0,
                                                    400,
                                                )
                                            }}{{
                                                decodedBaseCleanText.length >
                                                    400
                                                    ? '...'
                                                    : ''
                                            }}
                                        </p>
                                    </div>
                                    <div v-else
                                        class="border-warning/50 bg-warning/10 mb-4 rounded-lg border-2 border-dashed p-4">
                                        <p class="text-warning text-sm">
                                            <SparklesIcon class="mr-1 inline h-4 w-4" />
                                            This transcription needs to be
                                            cleaned before benchmarking.
                                        </p>
                                    </div>

                                    <Link :href="`/transcriptions/${baseTranscription!.id}`"
                                        class="hover:shadow-glow-sm inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-6 py-3 font-medium text-primary-foreground transition-all hover:bg-primary/90">
                                        <ArrowTopRightOnSquareIcon class="h-4 w-4" />
                                        {{
                                            baseIsValidated
                                                ? 'View Transcription'
                                                : baseIsCleaned
                                                    ? 'Review & Validate'
                                                    : 'Clean Transcription'
                                        }}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Quick Stats & Actions -->
                    <div class="space-y-6">
                        <!-- Status Card -->
                        <div class="overflow-hidden rounded-xl border border-border bg-card">
                            <div class="bg-primary px-6 py-8 text-center">
                                <div
                                    class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-white/20 backdrop-blur-sm">
                                    <ChartBarIcon class="h-7 w-7 text-white" />
                                </div>
                                <p class="text-sm font-medium text-white/80">
                                    Current Status
                                </p>
                                <p class="mt-1 text-2xl font-bold text-white capitalize">
                                    {{ audioSample.status.replace('_', ' ') }}
                                </p>
                            </div>
                            <div class="divide-y divide-border">
                                <div class="flex items-center justify-between px-6 py-4">
                                    <span class="text-sm text-muted-foreground">Audio</span>
                                    <span class="inline-flex items-center gap-1 text-sm font-medium" :class="hasAudio
                                            ? 'text-success'
                                            : 'text-muted-foreground'
                                        ">
                                        <CheckCircleIcon v-if="hasAudio" class="h-4 w-4" />
                                        <XCircleIcon v-else class="h-4 w-4" />
                                        {{ hasAudio ? 'Uploaded' : 'Missing' }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between px-6 py-4">
                                    <span class="text-sm text-muted-foreground">Transcript</span>
                                    <span class="inline-flex items-center gap-1 text-sm font-medium" :class="hasBaseTranscription
                                            ? 'text-success'
                                            : 'text-muted-foreground'
                                        ">
                                        <CheckCircleIcon v-if="hasBaseTranscription" class="h-4 w-4" />
                                        <XCircleIcon v-else class="h-4 w-4" />
                                        {{
                                            hasBaseTranscription
                                                ? 'Linked'
                                                : 'Not linked'
                                        }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between px-6 py-4">
                                    <span class="text-sm text-muted-foreground">Cleaned</span>
                                    <span class="inline-flex items-center gap-1 text-sm font-medium" :class="baseIsCleaned
                                            ? 'text-success'
                                            : 'text-muted-foreground'
                                        ">
                                        <CheckCircleIcon v-if="baseIsCleaned" class="h-4 w-4" />
                                        <XCircleIcon v-else class="h-4 w-4" />
                                        {{ baseIsCleaned ? 'Yes' : 'No' }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between px-6 py-4">
                                    <span class="text-sm text-muted-foreground">Validated</span>
                                    <span class="inline-flex items-center gap-1 text-sm font-medium" :class="baseIsValidated
                                            ? 'text-success'
                                            : 'text-muted-foreground'
                                        ">
                                        <CheckCircleIcon v-if="baseIsValidated" class="h-4 w-4" />
                                        <XCircleIcon v-else class="h-4 w-4" />
                                        {{ baseIsValidated ? 'Yes' : 'No' }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between px-6 py-4">
                                    <span class="text-sm text-muted-foreground">Benchmarks</span>
                                    <span class="text-sm font-semibold text-foreground">
                                        {{ asrTranscriptions.length }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Info -->
                        <div class="rounded-xl border border-border bg-card p-6">
                            <h3 class="mb-4 text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                                Details
                            </h3>
                            <dl class="space-y-3 text-sm">
                                <div class="flex justify-between">
                                    <dt class="text-muted-foreground">ID</dt>
                                    <dd class="font-mono text-foreground">
                                        #{{ audioSample.id }}
                                    </dd>
                                </div>
                                <div v-if="audioMedia?.size" class="flex justify-between">
                                    <dt class="text-muted-foreground">
                                        File Size
                                    </dt>
                                    <dd class="text-foreground">
                                        {{
                                            (
                                                audioMedia.size /
                                                1024 /
                                                1024
                                            ).toFixed(2)
                                        }}
                                        MB
                                    </dd>
                                </div>
                                <div v-if="audioSample.source_url" class="flex justify-between">
                                    <dt class="text-muted-foreground">
                                        Source
                                    </dt>
                                    <dd class="max-w-36 truncate text-foreground" :title="audioSample.source_url">
                                        {{ audioSample.source_url }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>

                <!-- ASR Benchmark Section -->
                <div id="benchmark-step" class="mt-6">
                    <AudioSampleBenchmarkSection :audio-sample-id="audioSample.id" :is-validated="baseIsValidated"
                        :show-transcription-form="showTranscriptionForm" :show-manual-entry-form="showManualEntryForm"
                        :transcriptions="asrTranscriptions" :asr-providers="asrProviders"
                        :asr-provider-options="asrProviderOptions" :asr-provider-models="asrProviderModels"
                        :loading-asr-models="loadingAsrModels" :transcribe-form="transcribeForm"
                        :manual-transcription-form="manualTranscriptionForm"
                        :manual-provider-selection="manualProviderSelection"
                        :manual-provider-custom="manualProviderCustom" :manual-model-selection="manualModelSelection"
                        :manual-model-custom="manualModelCustom" :manual-model-options="manualModelOptions"
                        :is-manual-provider-custom="isManualProviderCustom"
                        :is-manual-model-custom="isManualModelCustom" :manual-provider-value="manualProviderValue"
                        :manual-model-value="manualModelValue" :format-error-rate="formatErrorRate"
                        :get-wer-color="getWerColor" :get-source-color="getSourceColor" @update:show-transcription-form="
                            (value) => (showTranscriptionForm = value)
                        " @update:show-manual-entry-form="
                            (value) => (showManualEntryForm = value)
                        " @update:manual-provider-selection="
                            (value) => (manualProviderSelection = value)
                        " @update:manual-provider-custom="
                            (value) => (manualProviderCustom = value)
                        " @update:manual-model-selection="
                            (value) => (manualModelSelection = value)
                        " @update:manual-model-custom="
                            (value) => (manualModelCustom = value)
                        " @submit-transcription="submitTranscription"
                        @submit-manual-transcription="submitManualTranscription"
                        @delete-transcription="deleteTranscription" />
                </div>
            </div></div>
        <!-- Link Transcription Modal -->
        <LinkTranscriptionModal :is-open="showLinkModal" :audio-sample-id="audioSample.id"
            :audio-sample-name="audioSample.name" @close="showLinkModal = false" @linked="handleLinked" />
    </AppLayout>
</template>
