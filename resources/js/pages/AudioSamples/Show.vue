<script setup lang="ts">
import AlertError from '@/components/AlertError.vue';
import AudioPlayer from '@/components/AudioPlayer.vue';
import AudioSampleBenchmarkSection from '@/components/audio-samples/AudioSampleBenchmarkSection.vue';
import AudioSampleCleanSection from '@/components/audio-samples/AudioSampleCleanSection.vue';
import AudioSampleContextPanel from '@/components/audio-samples/AudioSampleContextPanel.vue';
import AudioSampleRecleanSection from '@/components/audio-samples/AudioSampleRecleanSection.vue';
import AudioSampleStatsPanel from '@/components/audio-samples/AudioSampleStatsPanel.vue';
import AudioSampleTextReview from '@/components/audio-samples/AudioSampleTextReview.vue';
import AudioSampleWorkflowCard from '@/components/audio-samples/AudioSampleWorkflowCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type {
    AsrProvider,
    AudioMedia,
    AudioSampleDetail,
    LlmModel,
    LlmProvider,
    Preset,
} from '@/types/audio-samples';
import {
    CloudArrowUpIcon,
    MusicalNoteIcon,
} from '@heroicons/vue/24/outline';
import { Head, router, useForm } from '@inertiajs/vue3';
import * as Diff from 'diff';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    audioSample: AudioSampleDetail;
    audioMedia: AudioMedia | null;
    presets: Record<string, Preset>;
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

// Status helpers - aligned with AudioSample model statuses
const isPendingTranscript = computed(
    () => props.audioSample.status === 'pending_transcript',
);
const isImported = computed(() => props.audioSample.status === 'imported');
const isCleaning = computed(() => props.audioSample.status === 'cleaning');
const isCleaned = computed(() => props.audioSample.status === 'cleaned');
const isValidated = computed(() => props.audioSample.status === 'validated');
const isFailed = computed(() => props.audioSample.status === 'failed');

// Content helpers
const hasRawText = computed(() => !!props.audioSample.reference_text_raw);
const hasCleanedText = computed(() => !!props.audioSample.reference_text_clean);
const needsTranscript = computed(() => !hasRawText.value);

// Action helpers - what can be done at this stage
const canBeCleaned = computed(
    () => hasRawText.value && !isCleaning.value && !isPendingTranscript.value,
);
const canBeValidated = computed(
    () => hasCleanedText.value && (isCleaned.value || isFailed.value),
);
const canBeTranscribed = computed(() => isValidated.value && hasAudio.value);

const workflowRequirements = computed(() => [
    { label: 'Audio file', missing: !hasAudio.value },
    { label: 'Reference transcript', missing: !hasRawText.value },
    { label: 'Cleaned text', missing: !hasCleanedText.value },
    { label: 'Validated', missing: !isValidated.value },
]);

const workflowCard = computed(() => {
    if (needsTranscript.value) {
        return {
            title: 'Import transcript',
            description: 'Upload a reference transcript to continue.',
            actionLabel: 'Upload transcript',
            actionHref: '#import-step',
            actionDisabled: false,
            actionReason: null,
        };
    }

    if (isImported.value || isCleaning.value || isFailed.value) {
        return {
            title: 'Clean transcript',
            description: isCleaning.value
                ? 'Cleaning is in progress.'
                : 'Run cleaning to generate a cleaned transcript.',
            actionLabel: isCleaning.value ? 'Cleaning…' : 'Run clean',
            actionHref: '#clean-step',
            actionDisabled: isCleaning.value || !canBeCleaned.value,
            actionReason: !hasRawText.value
                ? 'Reference transcript required'
                : isCleaning.value
                  ? 'Cleaning in progress'
                  : null,
        };
    }

    if (isCleaned.value) {
        return {
            title: 'Validate cleaned text',
            description: 'Review changes and confirm the cleaned text.',
            actionLabel: 'Validate',
            actionHref: '#validate-step',
            actionDisabled: !canBeValidated.value || isEditing.value,
            actionReason: isEditing.value
                ? 'Save edits first'
                : 'Cleaned text required',
        };
    }

    if (isValidated.value) {
        return {
            title: 'Benchmark',
            description: hasAudio.value
                ? 'Run ASR benchmarks to compare accuracy.'
                : 'Upload audio to benchmark this sample.',
            actionLabel: 'Run ASR',
            actionHref: '#benchmark-step',
            actionDisabled: !canBeTranscribed.value,
            actionReason: !hasAudio.value ? 'Audio file required' : null,
        };
    }

    return {
        title: 'Import transcript',
        description: 'Upload a reference transcript to continue.',
        actionLabel: 'Upload transcript',
        actionHref: '#import-step',
        actionDisabled: false,
        actionReason: null,
    };
});


// View state
const activeView = ref<'cleaned' | 'original' | 'side-by-side' | 'diff'>(
    'cleaned',
);
const copiedCleaned = ref(false);
const copiedOriginal = ref(false);
const isEditing = ref(false);
const editedText = ref('');
const showRecleanForm = ref(false);
const showLlmOptions = ref(false);

// Set default view based on status
watch(
    () => props.audioSample.status,
    (status) => {
        if (status === 'imported' || status === 'pending_transcript') {
            activeView.value = 'original';
        } else if (hasCleanedText.value) {
            activeView.value = 'diff';
        }
    },
    { immediate: true },
);

// Cleaning form
const cleanForm = useForm({
    preset: props.audioSample.processing_run?.preset ?? 'titles_only',
    mode: 'rule' as 'rule' | 'llm',
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
});

// LLM providers and models state
const llmProviders = ref<Record<string, LlmProvider>>({});
const loadingModels = ref(false);
const providerModels = ref<LlmModel[]>([]);

const presetOptions = computed(() =>
    Object.entries(props.presets).map(([key, value]) => ({
        id: key,
        name: value.name,
        description: value.description,
    })),
);

// Provider options for dropdown
const providerOptions = computed(() =>
    Object.entries(llmProviders.value).map(([key, value]) => ({
        id: key,
        name: value.name,
        hasCredential: value.has_credential,
    })),
);

// Get selected model display name
const selectedModelDisplay = computed(() => {
    const model = providerModels.value.find(
        (m) => m.id === cleanForm.llm_model,
    );
    return model?.name || cleanForm.llm_model;
});

// Fetch LLM providers on mount
const fetchProviders = async () => {
    try {
        const response = await fetch('/api/llm/providers');
        const data = await response.json();
        llmProviders.value = data;

        // Set initial models for default provider
        if (data[cleanForm.llm_provider]) {
            providerModels.value = data[cleanForm.llm_provider].models;
        }
    } catch (error) {
        console.error('Failed to fetch LLM providers:', error);
    }
};

// Fetch models for selected provider
const fetchModelsForProvider = async (provider: string) => {
    loadingModels.value = true;
    try {
        const response = await fetch(`/api/llm/providers/${provider}/models`);
        const data = await response.json();
        providerModels.value = data.models;

        // Update selected model to provider default if current not available
        const modelIds = data.models.map((m: LlmModel) => m.id);
        if (!modelIds.includes(cleanForm.llm_model)) {
            cleanForm.llm_model = data.default;
        }
    } catch (error) {
        console.error('Failed to fetch models:', error);
        providerModels.value = llmProviders.value[provider]?.models || [];
    } finally {
        loadingModels.value = false;
    }
};

// Watch provider changes
watch(
    () => cleanForm.llm_provider,
    (newProvider) => {
        fetchModelsForProvider(newProvider);
    },
);

// Fetch providers on mount
onMounted(() => {
    fetchProviders();
});

const submitClean = () => {
    cleanForm.post(`/audio-samples/${props.audioSample.id}/clean`, {
        preserveScroll: true,
    });
};

// Edit form
const updateForm = useForm({
    reference_text_clean: '',
});

const startEditing = () => {
    editedText.value = props.audioSample.reference_text_clean || '';
    isEditing.value = true;
};

const cancelEditing = () => {
    isEditing.value = false;
    editedText.value = '';
};

const saveEdit = () => {
    updateForm.reference_text_clean = editedText.value;
    updateForm.patch(`/audio-samples/${props.audioSample.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            isEditing.value = false;
        },
    });
};

// Transcript upload form
const transcriptForm = useForm({
    transcript: null as File | null,
});

const uploadTranscript = () => {
    if (!transcriptForm.transcript) return;
    transcriptForm.post(`/audio-samples/${props.audioSample.id}/transcript`, {
        preserveScroll: true,
        onSuccess: () => {
            transcriptForm.reset();
        },
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


// Validation
const validateForm = useForm({});

const toggleValidation = () => {
    if (props.audioSample.validated_at) {
        router.delete(`/audio-samples/${props.audioSample.id}/validate`);
    } else {
        validateForm.post(`/audio-samples/${props.audioSample.id}/validate`);
    }
};

const deleteAudioSample = () => {
    if (confirm('Are you sure you want to delete this audio sample?')) {
        router.delete(`/audio-samples/${props.audioSample.id}`);
    }
};

// Color helpers

// Model pricing per 1M tokens (input/output) - approximations based on typical prices
const modelPricing: Record<string, { input: number; output: number }> = {
    // OpenRouter / Anthropic
    'anthropic/claude-sonnet-4': { input: 3, output: 15 },
    'anthropic/claude-3.5-sonnet': { input: 3, output: 15 },
    'anthropic/claude-3-opus': { input: 15, output: 75 },
    'anthropic/claude-3-haiku': { input: 0.25, output: 1.25 },
    'claude-sonnet-4-20250514': { input: 3, output: 15 },
    'claude-3-5-sonnet-20241022': { input: 3, output: 15 },
    // OpenAI
    'gpt-4o': { input: 2.5, output: 10 },
    'gpt-4o-mini': { input: 0.15, output: 0.6 },
    'gpt-4-turbo': { input: 10, output: 30 },
    // Google
    'gemini-1.5-pro': { input: 1.25, output: 5 },
    'gemini-1.5-flash': { input: 0.075, output: 0.3 },
    'gemini-2.0-flash': { input: 0.1, output: 0.4 },
    // Groq (free tier has limits)
    'llama-3.3-70b-versatile': { input: 0.59, output: 0.79 },
    'llama-3.1-8b-instant': { input: 0.05, output: 0.08 },
    'mixtral-8x7b-32768': { input: 0.24, output: 0.24 },
};

// Token estimation (rough approximation: ~4 chars per token for English, ~2-3 for Hebrew/Yiddish)
const estimateTokens = (text: string): number => {
    if (!text) return 0;
    // Hebrew/Yiddish characters are typically 1 token per 2-3 chars
    // Use conservative estimate
    const hebrewChars = (text.match(/[\u0590-\u05FF]/g) || []).length;
    const otherChars = text.length - hebrewChars;
    return Math.ceil(hebrewChars / 2 + otherChars / 4);
};

// Get the default LLM prompt to include in estimation
const defaultPromptLength = 500; // Approximate length of cleaning prompt template

const estimatedTokens = computed(() => {
    const textTokens = estimateTokens(
        props.audioSample.reference_text_raw || '',
    );
    const promptTokens = defaultPromptLength; // The system prompt
    const inputTokens = textTokens + promptTokens;
    // Output is typically similar to or slightly less than the cleaned text
    const outputTokens = Math.ceil(textTokens * 0.9); // Assume 10% reduction
    return {
        input: inputTokens,
        output: outputTokens,
        total: inputTokens + outputTokens,
    };
});

const estimatedCost = computed(() => {
    const pricing = modelPricing[cleanForm.llm_model] || {
        input: 3,
        output: 15,
    }; // Default to Claude Sonnet pricing
    const inputCost = (estimatedTokens.value.input / 1_000_000) * pricing.input;
    const outputCost =
        (estimatedTokens.value.output / 1_000_000) * pricing.output;
    const totalCost = inputCost + outputCost;
    return {
        input: inputCost,
        output: outputCost,
        total: totalCost,
        formatted: totalCost < 0.01 ? `<$0.01` : `$${totalCost.toFixed(3)}`,
    };
});

const estimatedDurationSeconds = computed(() => {
    const tokens = estimatedTokens.value.total;
    return Math.max(5, Math.ceil(tokens / 1500));
});

// Computed statistics
const originalWords = computed(() => {
    if (!props.audioSample.reference_text_raw) return 0;
    return props.audioSample.reference_text_raw
        .split(/\s+/)
        .filter((w) => w.length > 0).length;
});

const cleanedWords = computed(() => {
    if (!props.audioSample.reference_text_clean) return 0;
    return props.audioSample.reference_text_clean
        .split(/\s+/)
        .filter((w) => w.length > 0).length;
});

const removedWords = computed(() => originalWords.value - cleanedWords.value);

const reductionPercentage = computed(() => {
    if (originalWords.value === 0) return 0;
    return ((removedWords.value / originalWords.value) * 100).toFixed(1);
});

const formattedMetrics = computed(() => {
    if (!props.audioSample.metrics) return [];
    return Object.entries(props.audioSample.metrics).map(([key, value]) => ({
        name: key.replace(/_/g, ' '),
        value: typeof value === 'number' ? value.toFixed(2) : value,
    }));
});

// Diff using the 'diff' library for word-level comparison
type DiffSegment = { type: 'same' | 'removed' | 'added'; text: string };

const charDiff = computed(() => {
    const original = props.audioSample.reference_text_raw || '';
    const cleaned = props.audioSample.reference_text_clean || '';

    // Use word-level diff which respects word boundaries
    const wordDiff = Diff.diffWords(original, cleaned);

    const segments: DiffSegment[] = [];

    for (const part of wordDiff) {
        if (part.added) {
            segments.push({ type: 'added', text: part.value });
        } else if (part.removed) {
            segments.push({ type: 'removed', text: part.value });
        } else {
            segments.push({ type: 'same', text: part.value });
        }
    }

    return segments;
});

const diffStats = computed(() => {
    const segments = charDiff.value;
    let removed = 0,
        added = 0,
        unchanged = 0;

    for (const seg of segments) {
        const len = seg.text.length;
        if (seg.type === 'removed') removed += len;
        else if (seg.type === 'added') added += len;
        else unchanged += len;
    }

    return { removed, added, unchanged };
});

// Copy to clipboard
const copyToClipboard = async (text: string, type: 'cleaned' | 'original') => {
    try {
        await navigator.clipboard.writeText(text);
        if (type === 'cleaned') {
            copiedCleaned.value = true;
            setTimeout(() => (copiedCleaned.value = false), 2000);
        } else {
            copiedOriginal.value = true;
            setTimeout(() => (copiedOriginal.value = false), 2000);
        }
    } catch (err) {
        console.error('Failed to copy text:', err);
    }
};

const copyCleanedText = () => {
    if (props.audioSample.reference_text_clean) {
        copyToClipboard(props.audioSample.reference_text_clean, 'cleaned');
    }
};

const copyOriginalText = () => {
    if (props.audioSample.reference_text_raw) {
        copyToClipboard(props.audioSample.reference_text_raw, 'original');
    }
};

// ==========================================
// ASR Transcription Section
// ==========================================

// Transcription state
const transcriptions = computed(() => props.audioSample.transcriptions || []);
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

        // Set initial models for default provider
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
            // Update selected model to provider default if current not available
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

// Fetch ASR providers on mount if validated
onMounted(() => {
    if (isValidated.value) {
        fetchAsrProviders();
    }
});

// Watch for validation status change
watch(isValidated, (validated) => {
    if (validated && Object.keys(asrProviders.value).length === 0) {
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

// Format WER/CER as percentage
const formatErrorRate = (rate: number | null): string => {
    if (rate === null) return 'N/A';
    const percent = rate > 1 ? rate : rate * 100;
    return `${percent.toFixed(2)}%`;
};

// Get WER color class
const getWerColor = (wer: number | null): string => {
    const normalized = normalizeErrorRate(wer);
    if (normalized === null) return 'text-muted-foreground';
    if (normalized <= 0.1) return 'text-emerald-600 dark:text-emerald-400';
    if (normalized <= 0.2) return 'text-green-600 dark:text-green-400';
    if (normalized <= 0.3) return 'text-yellow-600 dark:text-yellow-400';
    if (normalized <= 0.5) return 'text-orange-600 dark:text-orange-400';
    return 'text-red-600 dark:text-red-400';
};

// Get source badge color
const getSourceColor = (source: string): string => {
    if (source === 'generated') {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
    }
    return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400';
};
</script>

<template>
    <Head :title="audioSample.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8"
        >
            <!-- Error Alert -->
            <div v-if="isFailed && audioSample.error_message">
                <AlertError
                    :errors="[audioSample.error_message]"
                    title="Processing Failed"
                />
            </div>

            <!-- Context Panel -->
            <div class="space-y-6">
                <AudioSampleContextPanel
                    :audio-sample="audioSample"
                    :audio-media="audioMedia"
                    :has-audio="hasAudio"
                    :has-raw-text="hasRawText"
                    :has-cleaned-text="hasCleanedText"
                    @delete="deleteAudioSample"
                />

                <AudioSampleWorkflowCard
                    :title="workflowCard.title"
                    :description="workflowCard.description"
                    :action-label="workflowCard.actionLabel"
                    :action-href="workflowCard.actionHref"
                    :action-disabled="workflowCard.actionDisabled"
                    :action-reason="workflowCard.actionReason"
                    :requirements="workflowRequirements"
                />

                <div class="space-y-6">
                    <!-- Audio Section -->
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
                            <MusicalNoteIcon
                                class="h-8 w-8 shrink-0 text-rose-600 dark:text-rose-400"
                            />
                            <div class="flex-1">
                                <h2
                                    class="text-lg font-semibold text-rose-800 dark:text-rose-300"
                                >
                                    Audio File Missing
                                </h2>
                                <p
                                    class="mb-4 text-sm text-rose-700 dark:text-rose-400"
                                >
                                    This audio sample is missing its audio file.
                                    Upload an audio file to complete the sample.
                                </p>
                                <form
                                    @submit.prevent="uploadAudio"
                                    class="flex flex-col gap-3 sm:flex-row sm:items-end"
                                >
                                    <div class="flex-1">
                                        <label
                                            class="mb-1 block text-sm font-medium"
                                            >Audio File</label
                                        >
                                        <input
                                            type="file"
                                            accept=".mp3,.wav,.ogg,.m4a,.flac"
                                            @change="
                                                (e: any) =>
                                                    (audioForm.audio =
                                                        e.target.files[0])
                                            "
                                            class="block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                        />
                                    </div>
                                    <button
                                        type="submit"
                                        :disabled="
                                            !audioForm.audio ||
                                            audioForm.processing
                                        "
                                        class="rounded-lg bg-rose-600 px-4 py-2 font-medium text-white hover:bg-rose-700 disabled:opacity-50"
                                    >
                                        {{
                                            audioForm.processing
                                                ? 'Uploading...'
                                                : 'Upload Audio'
                                        }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Transcript: Upload Form -->
                    <div
                        v-if="needsTranscript"
                        id="import-step"
                        class="rounded-xl border-2 border-dashed border-yellow-300 bg-yellow-50 p-6 dark:border-yellow-700 dark:bg-yellow-900/20"
                    >
                        <div class="flex items-start gap-4">
                            <CloudArrowUpIcon
                                class="h-8 w-8 shrink-0 text-yellow-600 dark:text-yellow-400"
                            />
                            <div class="flex-1">
                                <h2
                                    class="text-lg font-semibold text-yellow-800 dark:text-yellow-300"
                                >
                                    Upload Reference Transcript
                                </h2>
                                <p
                                    class="mb-4 text-sm text-yellow-700 dark:text-yellow-400"
                                >
                                    This audio sample needs a reference
                                    transcript before it can be cleaned.
                                </p>
                                <form
                                    @submit.prevent="uploadTranscript"
                                    class="flex items-end gap-3"
                                >
                                    <div class="flex-1">
                                        <label
                                            class="mb-1 block text-sm font-medium"
                                            >Transcript File</label
                                        >
                                        <input
                                            type="file"
                                            accept=".txt,.docx,.doc,.pdf"
                                            @change="
                                                (e: any) =>
                                                    (transcriptForm.transcript =
                                                        e.target.files[0])
                                            "
                                            class="block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                        />
                                    </div>
                                    <button
                                        type="submit"
                                        :disabled="
                                            !transcriptForm.transcript ||
                                            transcriptForm.processing
                                        "
                                        class="rounded-lg bg-yellow-600 px-4 py-2 font-medium text-white hover:bg-yellow-700 disabled:opacity-50"
                                    >
                                        Upload
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <AudioSampleCleanSection
                        :is-visible="isImported && canBeCleaned"
                        :clean-form="cleanForm"
                        :preset-options="presetOptions"
                        :provider-options="providerOptions"
                        :provider-models="providerModels"
                        :selected-model-display="selectedModelDisplay"
                        :loading-models="loadingModels"
                        :estimated-tokens="estimatedTokens"
                        :estimated-cost="estimatedCost"
                        :estimated-duration-seconds="estimatedDurationSeconds"
                        :show-llm-options="showLlmOptions"
                        @update:show-llm-options="(value) => (showLlmOptions = value)"
                        @submit="submitClean"
                    />

                    <AudioSampleRecleanSection
                        :is-visible="(isCleaned || isFailed) && canBeCleaned"
                        :show-reclean-form="showRecleanForm"
                        :clean-form="cleanForm"
                        :preset-options="presetOptions"
                        :provider-options="providerOptions"
                        :provider-models="providerModels"
                        :selected-model-display="selectedModelDisplay"
                        :loading-models="loadingModels"
                        :estimated-tokens="estimatedTokens"
                        :estimated-cost="estimatedCost"
                        @update:show-reclean-form="(value) => (showRecleanForm = value)"
                        @submit="submitClean"
                    />

                    <!-- Replace Transcript option (if sample has a transcript already) -->
                    <details
                        v-if="hasRawText && !isPendingTranscript"
                        class="rounded-xl border bg-card"
                    >
                        <summary
                            class="flex cursor-pointer items-center gap-2 px-4 py-3 font-medium hover:bg-muted/50"
                        >
                            <CloudArrowUpIcon class="h-4 w-4" />
                            Replace Reference Transcript
                        </summary>
                        <div class="px-4 pb-4">
                            <p class="mb-4 text-sm text-muted-foreground">
                                Upload a new reference transcript to replace the
                                current one. This will reset any cleaned text
                                and validation status.
                            </p>
                            <form
                                @submit.prevent="uploadTranscript"
                                class="space-y-4"
                            >
                                <div>
                                    <label
                                        class="mb-1 block text-sm font-medium"
                                        >New Transcript File</label
                                    >
                                    <input
                                        type="file"
                                        accept=".txt,.docx,.doc,.pdf"
                                        @change="
                                            (e: any) =>
                                                (transcriptForm.transcript =
                                                    e.target.files[0])
                                        "
                                        class="block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                    />
                                </div>
                                <p
                                    class="text-sm text-amber-600 dark:text-amber-400"
                                >
                                    ⚠️ Replacing the transcript will discard all
                                    cleaned text and remove validation status.
                                </p>
                                <button
                                    type="submit"
                                    :disabled="
                                        !transcriptForm.transcript ||
                                        transcriptForm.processing
                                    "
                                    class="rounded-lg bg-amber-600 px-4 py-2 font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                                >
                                    {{
                                        transcriptForm.processing
                                            ? 'Uploading...'
                                            : 'Replace Transcript'
                                    }}
                                </button>
                            </form>
                        </div>
                    </details>

                    <!-- Replace Audio option (if sample has audio already) -->
                    <details v-if="hasAudio" class="rounded-xl border bg-card">
                        <summary
                            class="flex cursor-pointer items-center gap-2 px-4 py-3 font-medium hover:bg-muted/50"
                        >
                            <MusicalNoteIcon class="h-4 w-4" />
                            Replace Audio File
                        </summary>
                        <div class="px-4 pb-4">
                            <p class="mb-4 text-sm text-muted-foreground">
                                Upload a new audio file to replace the current
                                one.
                            </p>
                            <form
                                @submit.prevent="uploadAudio"
                                class="space-y-4"
                            >
                                <div>
                                    <label
                                        class="mb-1 block text-sm font-medium"
                                        >New Audio File</label
                                    >
                                    <input
                                        type="file"
                                        accept=".mp3,.wav,.ogg,.m4a,.flac"
                                        @change="
                                            (e: any) =>
                                                (audioForm.audio =
                                                    e.target.files[0])
                                        "
                                        class="block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    :disabled="
                                        !audioForm.audio || audioForm.processing
                                    "
                                    class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                >
                                    {{
                                        audioForm.processing
                                            ? 'Uploading...'
                                            : 'Replace Audio'
                                    }}
                                </button>
                            </form>
                        </div>
                    </details>

                    <AudioSampleStatsPanel
                        :has-cleaned-text="hasCleanedText"
                        :audio-sample="audioSample"
                        :cleaned-words="cleanedWords"
                        :original-words="originalWords"
                        :removed-words="removedWords"
                        :reduction-percentage="reductionPercentage"
                        :formatted-metrics="formattedMetrics"
                        :can-be-validated="canBeValidated"
                        :is-validated="isValidated"
                        :is-editing="isEditing"
                        :validate-processing="validateForm.processing"
                        @validate="toggleValidation"
                    />

                    <AudioSampleTextReview
                        :has-raw-text="hasRawText"
                        :has-cleaned-text="hasCleanedText"
                        :active-view="activeView"
                        :copied-original="copiedOriginal"
                        :copied-cleaned="copiedCleaned"
                        :is-editing="isEditing"
                        :edited-text="editedText"
                        :update-processing="updateForm.processing"
                        :original-text="audioSample.reference_text_raw"
                        :cleaned-text="audioSample.reference_text_clean"
                        :char-diff="charDiff"
                        :diff-stats="diffStats"
                        @update:active-view="(value) => (activeView = value)"
                        @update:edited-text="(value) => (editedText = value)"
                        @start-editing="startEditing"
                        @cancel-editing="cancelEditing"
                        @save-edit="saveEdit"
                        @copy-original="copyOriginalText"
                        @copy-cleaned="copyCleanedText"
                    />

                    <div id="benchmark-step">
                        <AudioSampleBenchmarkSection
                            :audio-sample-id="audioSample.id"
                            :is-validated="isValidated"
                            :show-transcription-form="showTranscriptionForm"
                            :show-manual-entry-form="showManualEntryForm"
                            :transcriptions="transcriptions"
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
        </div>
    </AppLayout>
</template>
