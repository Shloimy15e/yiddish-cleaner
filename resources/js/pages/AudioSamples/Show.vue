<script setup lang="ts">
import AlertError from '@/components/AlertError.vue';
import AudioPlayer from '@/components/AudioPlayer.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
    Listbox,
    ListboxButton,
    ListboxOption,
    ListboxOptions,
} from '@headlessui/vue';
import {
    ArrowDownTrayIcon,
    ArrowPathIcon,
    CheckIcon,
    ChevronUpDownIcon,
    ClipboardDocumentIcon,
    CloudArrowUpIcon,
    CpuChipIcon,
    DocumentArrowDownIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
    MicrophoneIcon,
    MusicalNoteIcon,
    PencilIcon,
    PlusIcon,
    SparklesIcon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { Head, router, useForm } from '@inertiajs/vue3';
import * as Diff from 'diff';
import { computed, onMounted, ref, watch } from 'vue';

interface Preset {
    name: string;
    description: string;
    processors: string[];
}

interface LlmModel {
    id: string;
    name: string;
    context_length?: number;
}

interface LlmProvider {
    name: string;
    default_model: string;
    has_credential: boolean;
    models: LlmModel[];
}

interface AudioMedia {
    url: string;
    name: string;
    size: number;
    mime_type: string;
}

interface AudioSample {
    id: number;
    name: string;
    reference_text_raw: string;
    reference_text_clean: string;
    status: string;
    error_message: string | null;
    clean_rate: number | null;
    clean_rate_category: string | null;
    metrics: Record<string, number> | null;
    removals: Array<{ type: string; original: string; count: number }> | null;
    validated_at: string | null;
    created_at: string;
    processing_run: {
        id: number;
        preset: string;
        mode: string;
    } | null;
    transcriptions?: Transcription[];
}

interface Transcription {
    id: number;
    model_name: string;
    model_version: string | null;
    source: 'generated' | 'imported';
    hypothesis_text: string;
    wer: number | null;
    cer: number | null;
    substitutions: number;
    insertions: number;
    deletions: number;
    reference_words: number;
    notes: string | null;
    created_at: string;
}

interface AsrProvider {
    name: string;
    default_model: string;
    has_credential: boolean;
    models: { id: string; name: string }[];
    async: boolean;
    description: string;
}

const props = defineProps<{
    audioSample: AudioSample;
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

// Action helpers - what can be done at this stage
const canBeCleaned = computed(
    () => hasRawText.value && !isCleaning.value && !isPendingTranscript.value,
);
const canBeValidated = computed(
    () => hasCleanedText.value && (isCleaned.value || isFailed.value),
);
const canBeTranscribed = computed(() => isValidated.value && hasAudio.value);

// Workflow step (1-4)
const workflowStep = computed(() => {
    if (isPendingTranscript.value) return 1;
    if (isImported.value) return 2;
    if (isCleaning.value) return 2;
    if (isCleaned.value) return 3;
    if (isValidated.value) return 4;
    if (isFailed.value) return 2; // Failed during cleaning
    return 1;
});

// Workflow steps for progress indicator
const workflowSteps = [
    { step: 1, label: 'Import', description: 'Add transcript' },
    { step: 2, label: 'Clean', description: 'Process text' },
    { step: 3, label: 'Validate', description: 'Review & approve' },
    { step: 4, label: 'Benchmark', description: 'Run ASR tests' },
];

// View state
const activeView = ref<'cleaned' | 'original' | 'side-by-side' | 'diff'>(
    'cleaned',
);
const copiedCleaned = ref(false);
const copiedOriginal = ref(false);
const isEditing = ref(false);
const editedText = ref('');
const showRecleanForm = ref(false);

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

// Format file size for display
const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
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
    model_name: '',
    model_version: '',
    hypothesis_text: '',
    notes: '',
});

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
    manualTranscriptionForm.post(
        `/audio-samples/${props.audioSample.id}/transcriptions`,
        {
            preserveScroll: true,
            onSuccess: () => {
                showManualEntryForm.value = false;
                manualTranscriptionForm.reset();
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

// Format WER/CER as percentage
const formatErrorRate = (rate: number | null): string => {
    if (rate === null) return 'N/A';
    return `${(rate * 100).toFixed(2)}%`;
};

// Get WER color class
const getWerColor = (wer: number | null): string => {
    if (wer === null) return 'text-muted-foreground';
    if (wer <= 0.1) return 'text-emerald-600 dark:text-emerald-400';
    if (wer <= 0.2) return 'text-green-600 dark:text-green-400';
    if (wer <= 0.3) return 'text-yellow-600 dark:text-yellow-400';
    if (wer <= 0.5) return 'text-orange-600 dark:text-orange-400';
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
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Error Alert -->
            <div v-if="isFailed && audioSample.error_message">
                <AlertError
                    :errors="[audioSample.error_message]"
                    title="Processing Failed"
                />
            </div>

            <!-- Header with Workflow Progress -->
            <div class="space-y-4">
                <!-- Title Row -->
                <div
                    class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start"
                >
                    <div>
                        <div class="mb-1 flex items-center gap-3">
                            <h1 class="text-2xl font-bold">
                                {{ audioSample.name }}
                            </h1>
                            <span
                                :class="[
                                    'rounded-full px-3 py-1 text-xs font-medium',
                                    getStatusColor(audioSample.status),
                                ]"
                            >
                                {{ getStatusLabel(audioSample.status) }}
                            </span>
                        </div>
                        <p class="text-muted-foreground">
                            Created {{ audioSample.created_at }}
                            <span v-if="audioSample.processing_run">
                                ·
                                {{
                                    audioSample.processing_run.preset.replace(
                                        /_/g,
                                        ' ',
                                    )
                                }}
                            </span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <!-- Download Dropdown (only if has cleaned text) -->
                        <div v-if="hasCleanedText" class="group relative">
                            <button
                                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                            >
                                <ArrowDownTrayIcon class="h-4 w-4" />
                                Download
                            </button>
                            <div
                                class="invisible absolute right-0 z-10 mt-1 w-48 rounded-lg border bg-popover opacity-0 shadow-lg transition-all group-hover:visible group-hover:opacity-100"
                            >
                                <a
                                    :href="`/audio-samples/${audioSample.id}/download`"
                                    class="flex items-center gap-2 rounded-t-lg px-4 py-2 hover:bg-muted"
                                >
                                    <DocumentArrowDownIcon class="h-4 w-4" />
                                    Cleaned (.docx)
                                </a>
                                <a
                                    :href="`/audio-samples/${audioSample.id}/download/text`"
                                    class="flex items-center gap-2 px-4 py-2 hover:bg-muted"
                                >
                                    <DocumentTextIcon class="h-4 w-4" />
                                    Cleaned (.txt)
                                </a>
                                <a
                                    v-if="hasRawText"
                                    :href="`/audio-samples/${audioSample.id}/download/original`"
                                    class="flex items-center gap-2 rounded-b-lg px-4 py-2 hover:bg-muted"
                                >
                                    <DocumentTextIcon class="h-4 w-4" />
                                    Original (.txt)
                                </a>
                            </div>
                        </div>

                        <!-- Validate Button (only if cleaned) -->
                        <button
                            v-if="canBeValidated"
                            @click="toggleValidation"
                            :disabled="validateForm.processing"
                            class="rounded-lg bg-green-600 px-4 py-2 font-medium text-white hover:bg-green-700 disabled:opacity-50"
                        >
                            Mark as Benchmark Ready
                        </button>
                        <button
                            v-else-if="isValidated"
                            @click="toggleValidation"
                            :disabled="validateForm.processing"
                            class="rounded-lg border px-4 py-2 font-medium hover:bg-muted"
                        >
                            Remove from Benchmark Ready
                        </button>

                        <button
                            @click="deleteAudioSample"
                            class="rounded-lg border border-red-200 px-4 py-2 font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
                        >
                            Delete
                        </button>
                    </div>
                </div>

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
                    class="rounded-xl border-2 border-dashed border-rose-300 bg-rose-50 p-6 dark:border-rose-700 dark:bg-rose-900/20"
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
                                class="flex items-end gap-3"
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
                                        !audioForm.audio || audioForm.processing
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
                    v-if="isPendingTranscript"
                    class="rounded-xl border-2 border-dashed border-yellow-300 bg-yellow-50 p-6 dark:border-yellow-700 dark:bg-yellow-900/20"
                >
                    <div class="flex items-start gap-4">
                        <CloudArrowUpIcon
                            class="h-8 w-8 flex-shrink-0 text-yellow-600 dark:text-yellow-400"
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
                                This audio sample needs a reference transcript
                                before it can be cleaned.
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
                                        accept=".txt,.docx,.pdf"
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

                <!-- Imported: Cleaning Form -->
                <div
                    v-if="isImported && canBeCleaned"
                    class="rounded-xl border-2 border-blue-200 bg-blue-50 p-6 dark:border-blue-800 dark:bg-blue-900/20"
                >
                    <h2
                        class="mb-4 flex items-center gap-2 text-lg font-semibold"
                    >
                        <SparklesIcon class="h-5 w-5 text-blue-600" />
                        Clean This Transcript
                    </h2>
                    <form @submit.prevent="submitClean" class="space-y-4">
                        <!-- Mode Selection -->
                        <div>
                            <label class="mb-1 block text-sm font-medium"
                                >Cleaning Mode</label
                            >
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    @click="cleanForm.mode = 'rule'"
                                    :class="[
                                        'flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 font-medium transition-colors',
                                        cleanForm.mode === 'rule'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'hover:bg-muted',
                                    ]"
                                >
                                    <CpuChipIcon class="h-4 w-4" />
                                    Rule-based
                                </button>
                                <button
                                    type="button"
                                    @click="cleanForm.mode = 'llm'"
                                    :class="[
                                        'flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 font-medium transition-colors',
                                        cleanForm.mode === 'llm'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'hover:bg-muted',
                                    ]"
                                >
                                    <SparklesIcon class="h-4 w-4" />
                                    AI (LLM)
                                </button>
                            </div>
                        </div>

                        <!-- Preset Selection (only for rule-based mode) -->
                        <div v-if="cleanForm.mode === 'rule'">
                            <label class="mb-1 block text-sm font-medium"
                                >Cleaning Preset</label
                            >
                            <Listbox v-model="cleanForm.preset">
                                <div class="relative">
                                    <ListboxButton
                                        class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20"
                                    >
                                        <span class="block truncate">{{
                                            presetOptions.find(
                                                (p) =>
                                                    p.id === cleanForm.preset,
                                            )?.name || cleanForm.preset
                                        }}</span>
                                        <span
                                            class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                                        >
                                            <ChevronUpDownIcon
                                                class="h-5 w-5 text-muted-foreground"
                                            />
                                        </span>
                                    </ListboxButton>
                                    <ListboxOptions
                                        class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none"
                                    >
                                        <ListboxOption
                                            v-for="preset in presetOptions"
                                            :key="preset.id"
                                            :value="preset.id"
                                            v-slot="{ active, selected }"
                                        >
                                            <li
                                                :class="[
                                                    'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                                    active
                                                        ? 'bg-primary/10 text-foreground'
                                                        : 'text-foreground',
                                                ]"
                                            >
                                                <span
                                                    :class="[
                                                        'block truncate',
                                                        selected &&
                                                            'font-medium',
                                                    ]"
                                                >
                                                    {{ preset.name }}
                                                </span>
                                                <span
                                                    class="block truncate text-xs text-muted-foreground"
                                                    >{{
                                                        preset.description
                                                    }}</span
                                                >
                                                <span
                                                    v-if="selected"
                                                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                                >
                                                    <CheckIcon
                                                        class="h-5 w-5"
                                                    />
                                                </span>
                                            </li>
                                        </ListboxOption>
                                    </ListboxOptions>
                                </div>
                            </Listbox>
                        </div>

                        <!-- LLM Options (if LLM mode) -->
                        <div
                            v-if="cleanForm.mode === 'llm'"
                            class="grid gap-4 border-t pt-2 md:grid-cols-2"
                        >
                            <div>
                                <label class="mb-1 block text-sm font-medium">
                                    LLM Provider
                                    <SparklesIcon
                                        class="ml-1 inline-block h-4 w-4 text-primary"
                                    />
                                </label>
                                <Listbox v-model="cleanForm.llm_provider">
                                    <div class="relative">
                                        <ListboxButton
                                            class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20"
                                        >
                                            <span class="block truncate">{{
                                                llmProviders[
                                                    cleanForm.llm_provider
                                                ]?.name ||
                                                cleanForm.llm_provider
                                            }}</span>
                                            <span
                                                class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                                            >
                                                <ChevronUpDownIcon
                                                    class="h-5 w-5 text-muted-foreground"
                                                />
                                            </span>
                                        </ListboxButton>
                                        <ListboxOptions
                                            class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none"
                                        >
                                            <ListboxOption
                                                v-for="provider in providerOptions"
                                                :key="provider.id"
                                                :value="provider.id"
                                                v-slot="{ active, selected }"
                                            >
                                                <li
                                                    :class="[
                                                        'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                                        active
                                                            ? 'bg-primary/10 text-foreground'
                                                            : 'text-foreground',
                                                    ]"
                                                >
                                                    <span
                                                        class="flex items-center gap-2"
                                                    >
                                                        <span
                                                            :class="[
                                                                'block truncate',
                                                                selected &&
                                                                    'font-medium',
                                                            ]"
                                                            >{{
                                                                provider.name
                                                            }}</span
                                                        >
                                                        <span
                                                            v-if="
                                                                !provider.hasCredential
                                                            "
                                                            class="text-xs text-amber-500"
                                                            >(no key)</span
                                                        >
                                                    </span>
                                                    <span
                                                        v-if="selected"
                                                        class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                                    >
                                                        <CheckIcon
                                                            class="h-5 w-5"
                                                        />
                                                    </span>
                                                </li>
                                            </ListboxOption>
                                        </ListboxOptions>
                                    </div>
                                </Listbox>
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium">
                                    Model
                                    <ArrowPathIcon
                                        v-if="loadingModels"
                                        class="ml-1 inline-block h-4 w-4 animate-spin"
                                    />
                                </label>
                                <Listbox
                                    v-model="cleanForm.llm_model"
                                    :disabled="loadingModels"
                                >
                                    <div class="relative">
                                        <ListboxButton
                                            class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:opacity-50"
                                        >
                                            <span class="block truncate">{{
                                                selectedModelDisplay
                                            }}</span>
                                            <span
                                                class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                                            >
                                                <ChevronUpDownIcon
                                                    class="h-5 w-5 text-muted-foreground"
                                                />
                                            </span>
                                        </ListboxButton>
                                        <ListboxOptions
                                            class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none"
                                        >
                                            <ListboxOption
                                                v-for="model in providerModels"
                                                :key="model.id"
                                                :value="model.id"
                                                v-slot="{ active, selected }"
                                            >
                                                <li
                                                    :class="[
                                                        'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                                        active
                                                            ? 'bg-primary/10 text-foreground'
                                                            : 'text-foreground',
                                                    ]"
                                                >
                                                    <span
                                                        :class="[
                                                            'block truncate',
                                                            selected &&
                                                                'font-medium',
                                                        ]"
                                                        >{{ model.name }}</span
                                                    >
                                                    <span
                                                        v-if="
                                                            model.context_length
                                                        "
                                                        class="block text-xs text-muted-foreground"
                                                        >{{
                                                            (
                                                                model.context_length /
                                                                1000
                                                            ).toFixed(0)
                                                        }}k context</span
                                                    >
                                                    <span
                                                        v-if="selected"
                                                        class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                                    >
                                                        <CheckIcon
                                                            class="h-5 w-5"
                                                        />
                                                    </span>
                                                </li>
                                            </ListboxOption>
                                        </ListboxOptions>
                                    </div>
                                </Listbox>
                            </div>
                        </div>

                        <!-- Cost Estimation (LLM mode only) -->
                        <div
                            v-if="cleanForm.mode === 'llm'"
                            class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20"
                        >
                            <div class="flex items-start gap-3">
                                <SparklesIcon
                                    class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="text-sm font-medium text-amber-800 dark:text-amber-200"
                                    >
                                        Estimated Cost
                                    </div>
                                    <div
                                        class="mt-1 grid grid-cols-3 gap-2 text-xs"
                                    >
                                        <div>
                                            <span
                                                class="text-amber-600 dark:text-amber-400"
                                                >Input:</span
                                            >
                                            <span class="ml-1 font-mono"
                                                >~{{
                                                    estimatedTokens.input.toLocaleString()
                                                }}
                                                tokens</span
                                            >
                                        </div>
                                        <div>
                                            <span
                                                class="text-amber-600 dark:text-amber-400"
                                                >Output:</span
                                            >
                                            <span class="ml-1 font-mono"
                                                >~{{
                                                    estimatedTokens.output.toLocaleString()
                                                }}
                                                tokens</span
                                            >
                                        </div>
                                        <div class="font-medium">
                                            <span
                                                class="text-amber-600 dark:text-amber-400"
                                                >Total:</span
                                            >
                                            <span
                                                class="ml-1 font-mono text-amber-800 dark:text-amber-200"
                                                >{{
                                                    estimatedCost.formatted
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                    <p
                                        class="mt-1 text-xs text-amber-600 dark:text-amber-400"
                                    >
                                        Estimates based on
                                        {{ selectedModelDisplay }} pricing.
                                        Actual costs may vary.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="cleanForm.processing"
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-2 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                            >
                                <ArrowPathIcon
                                    v-if="cleanForm.processing"
                                    class="h-4 w-4 animate-spin"
                                />
                                <SparklesIcon v-else class="h-4 w-4" />
                                {{
                                    cleanForm.processing
                                        ? 'Cleaning...'
                                        : 'Clean Transcript'
                                }}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Re-clean option (if already cleaned) - More prominent card -->
                <div
                    v-if="isCleaned && canBeCleaned"
                    class="rounded-xl border-2 border-amber-200 bg-amber-50 p-6 dark:border-amber-800 dark:bg-amber-900/20"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h2
                            class="flex items-center gap-2 text-lg font-semibold"
                        >
                            <ArrowPathIcon class="h-5 w-5 text-amber-600" />
                            Re-clean with Different Settings
                        </h2>
                        <button
                            type="button"
                            @click="showRecleanForm = !showRecleanForm"
                            class="text-sm font-medium text-amber-600 hover:text-amber-700"
                        >
                            {{
                                showRecleanForm
                                    ? 'Hide Options'
                                    : 'Show Options'
                            }}
                        </button>
                    </div>
                    <p
                        v-if="!showRecleanForm"
                        class="text-sm text-muted-foreground"
                    >
                        Not satisfied with the results? Try a different cleaning
                        method or AI model.
                    </p>
                    <form
                        v-if="showRecleanForm"
                        @submit.prevent="submitClean"
                        class="space-y-4"
                    >
                        <!-- Mode Selection -->
                        <div>
                            <label class="mb-1 block text-sm font-medium"
                                >Mode</label
                            >
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    @click="cleanForm.mode = 'rule'"
                                    :class="[
                                        'flex-1 rounded-lg border px-3 py-2',
                                        cleanForm.mode === 'rule'
                                            ? 'bg-primary text-primary-foreground'
                                            : '',
                                    ]"
                                >
                                    Rule-based
                                </button>
                                <button
                                    type="button"
                                    @click="cleanForm.mode = 'llm'"
                                    :class="[
                                        'flex-1 rounded-lg border px-3 py-2',
                                        cleanForm.mode === 'llm'
                                            ? 'bg-primary text-primary-foreground'
                                            : '',
                                    ]"
                                >
                                    AI (LLM)
                                </button>
                            </div>
                        </div>
                        <!-- Preset Selection (only for rule-based mode) -->
                        <div v-if="cleanForm.mode === 'rule'">
                            <label class="mb-1 block text-sm font-medium"
                                >Cleaning Preset</label
                            >
                            <Listbox v-model="cleanForm.preset">
                                <div class="relative">
                                    <ListboxButton
                                        class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20"
                                    >
                                        <span class="block truncate">{{
                                            presetOptions.find(
                                                (p) =>
                                                    p.id === cleanForm.preset,
                                            )?.name || cleanForm.preset
                                        }}</span>
                                        <span
                                            class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                                        >
                                            <ChevronUpDownIcon
                                                class="h-5 w-5 text-muted-foreground"
                                            />
                                        </span>
                                    </ListboxButton>
                                    <ListboxOptions
                                        class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none"
                                    >
                                        <ListboxOption
                                            v-for="preset in presetOptions"
                                            :key="preset.id"
                                            :value="preset.id"
                                            v-slot="{ active, selected }"
                                        >
                                            <li
                                                :class="[
                                                    'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                                    active
                                                        ? 'bg-primary/10 text-foreground'
                                                        : 'text-foreground',
                                                ]"
                                            >
                                                <span
                                                    :class="[
                                                        'block truncate',
                                                        selected &&
                                                            'font-medium',
                                                    ]"
                                                    >{{ preset.name }}</span
                                                >
                                                <span
                                                    v-if="selected"
                                                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                                >
                                                    <CheckIcon
                                                        class="h-5 w-5"
                                                    />
                                                </span>
                                            </li>
                                        </ListboxOption>
                                    </ListboxOptions>
                                </div>
                            </Listbox>
                        </div>
                        <!-- LLM Options (if LLM mode) -->
                        <div
                            v-if="cleanForm.mode === 'llm'"
                            class="grid gap-4 md:grid-cols-2"
                        >
                            <div>
                                <label class="mb-1 block text-sm font-medium"
                                    >Provider</label
                                >
                                <Listbox v-model="cleanForm.llm_provider">
                                    <div class="relative">
                                        <ListboxButton
                                            class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20"
                                        >
                                            <span class="block truncate">{{
                                                llmProviders[
                                                    cleanForm.llm_provider
                                                ]?.name ||
                                                cleanForm.llm_provider
                                            }}</span>
                                            <span
                                                class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                                            >
                                                <ChevronUpDownIcon
                                                    class="h-5 w-5 text-muted-foreground"
                                                />
                                            </span>
                                        </ListboxButton>
                                        <ListboxOptions
                                            class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none"
                                        >
                                            <ListboxOption
                                                v-for="provider in providerOptions"
                                                :key="provider.id"
                                                :value="provider.id"
                                                v-slot="{ active, selected }"
                                            >
                                                <li
                                                    :class="[
                                                        'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                                        active
                                                            ? 'bg-primary/10 text-foreground'
                                                            : 'text-foreground',
                                                    ]"
                                                >
                                                    <span
                                                        class="flex items-center gap-2"
                                                    >
                                                        <span
                                                            :class="[
                                                                'block truncate',
                                                                selected &&
                                                                    'font-medium',
                                                            ]"
                                                            >{{
                                                                provider.name
                                                            }}</span
                                                        >
                                                        <span
                                                            v-if="
                                                                !provider.hasCredential
                                                            "
                                                            class="text-xs text-amber-500"
                                                            >(no key)</span
                                                        >
                                                    </span>
                                                    <span
                                                        v-if="selected"
                                                        class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                                    >
                                                        <CheckIcon
                                                            class="h-5 w-5"
                                                        />
                                                    </span>
                                                </li>
                                            </ListboxOption>
                                        </ListboxOptions>
                                    </div>
                                </Listbox>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">
                                    Model
                                    <ArrowPathIcon
                                        v-if="loadingModels"
                                        class="ml-1 inline-block h-4 w-4 animate-spin"
                                    />
                                </label>
                                <Listbox
                                    v-model="cleanForm.llm_model"
                                    :disabled="loadingModels"
                                >
                                    <div class="relative">
                                        <ListboxButton
                                            class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:opacity-50"
                                        >
                                            <span class="block truncate">{{
                                                selectedModelDisplay
                                            }}</span>
                                            <span
                                                class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                                            >
                                                <ChevronUpDownIcon
                                                    class="h-5 w-5 text-muted-foreground"
                                                />
                                            </span>
                                        </ListboxButton>
                                        <ListboxOptions
                                            class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none"
                                        >
                                            <ListboxOption
                                                v-for="model in providerModels"
                                                :key="model.id"
                                                :value="model.id"
                                                v-slot="{ active, selected }"
                                            >
                                                <li
                                                    :class="[
                                                        'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                                        active
                                                            ? 'bg-primary/10 text-foreground'
                                                            : 'text-foreground',
                                                    ]"
                                                >
                                                    <span
                                                        :class="[
                                                            'block truncate',
                                                            selected &&
                                                                'font-medium',
                                                        ]"
                                                        >{{ model.name }}</span
                                                    >
                                                    <span
                                                        v-if="
                                                            model.context_length
                                                        "
                                                        class="block text-xs text-muted-foreground"
                                                        >{{
                                                            (
                                                                model.context_length /
                                                                1000
                                                            ).toFixed(0)
                                                        }}k context</span
                                                    >
                                                    <span
                                                        v-if="selected"
                                                        class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                                    >
                                                        <CheckIcon
                                                            class="h-5 w-5"
                                                        />
                                                    </span>
                                                </li>
                                            </ListboxOption>
                                        </ListboxOptions>
                                    </div>
                                </Listbox>
                            </div>
                        </div>
                        <!-- Cost Estimation (LLM mode only) -->
                        <div
                            v-if="cleanForm.mode === 'llm'"
                            class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20"
                        >
                            <div class="flex items-start gap-3">
                                <SparklesIcon
                                    class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="text-sm font-medium text-amber-800 dark:text-amber-200"
                                    >
                                        Estimated Cost
                                    </div>
                                    <div
                                        class="mt-1 grid grid-cols-3 gap-2 text-xs"
                                    >
                                        <div>
                                            <span
                                                class="text-amber-600 dark:text-amber-400"
                                                >Input:</span
                                            >
                                            <span class="ml-1 font-mono"
                                                >~{{
                                                    estimatedTokens.input.toLocaleString()
                                                }}
                                                tokens</span
                                            >
                                        </div>
                                        <div>
                                            <span
                                                class="text-amber-600 dark:text-amber-400"
                                                >Output:</span
                                            >
                                            <span class="ml-1 font-mono"
                                                >~{{
                                                    estimatedTokens.output.toLocaleString()
                                                }}
                                                tokens</span
                                            >
                                        </div>
                                        <div class="font-medium">
                                            <span
                                                class="text-amber-600 dark:text-amber-400"
                                                >Total:</span
                                            >
                                            <span
                                                class="ml-1 font-mono text-amber-800 dark:text-amber-200"
                                                >{{
                                                    estimatedCost.formatted
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                    <p
                                        class="mt-1 text-xs text-amber-600 dark:text-amber-400"
                                    >
                                        Estimates based on
                                        {{ selectedModelDisplay }} pricing.
                                        Actual costs may vary.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <p class="text-sm text-amber-600 dark:text-amber-400">
                            ⚠️ Re-cleaning will overwrite the current cleaned
                            text and remove validation status.
                        </p>
                        <button
                            type="submit"
                            :disabled="cleanForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                        >
                            <ArrowPathIcon
                                v-if="cleanForm.processing"
                                class="h-4 w-4 animate-spin"
                            />
                            {{
                                cleanForm.processing
                                    ? 'Cleaning...'
                                    : 'Re-clean Transcript'
                            }}
                        </button>
                    </form>
                </div>

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
                            current one. This will reset any cleaned text and
                            validation status.
                        </p>
                        <form
                            @submit.prevent="uploadTranscript"
                            class="space-y-4"
                        >
                            <div>
                                <label class="mb-1 block text-sm font-medium"
                                    >New Transcript File</label
                                >
                                <input
                                    type="file"
                                    accept=".txt,.docx,.pdf"
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
                            Upload a new audio file to replace the current one.
                        </p>
                        <form @submit.prevent="uploadAudio" class="space-y-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium"
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

                <!-- Stats Grid (only if cleaned) -->
                <div
                    v-if="hasCleanedText"
                    class="grid grid-cols-2 gap-4 md:grid-cols-5"
                >
                    <div class="rounded-xl border bg-card p-4">
                        <div class="text-sm text-muted-foreground">
                            Clean Rate
                        </div>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="text-2xl font-bold"
                                >{{ audioSample.clean_rate ?? '-' }}%</span
                            >
                            <span
                                v-if="audioSample.clean_rate_category"
                                :class="[
                                    'rounded-full px-2 py-0.5 text-xs font-medium capitalize',
                                    getCategoryColor(
                                        audioSample.clean_rate_category,
                                    ),
                                ]"
                            >
                                {{ audioSample.clean_rate_category }}
                            </span>
                        </div>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <div class="text-sm text-muted-foreground">
                            Reduction
                        </div>
                        <div class="text-2xl font-bold text-blue-600">
                            {{ reductionPercentage }}%
                        </div>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <div class="text-sm text-muted-foreground">
                            Words Removed
                        </div>
                        <div class="text-2xl font-bold text-rose-500">
                            {{ removedWords }}
                        </div>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <div class="text-sm text-muted-foreground">
                            Original Words
                        </div>
                        <div class="text-2xl font-bold">
                            {{ originalWords }}
                        </div>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <div class="text-sm text-muted-foreground">
                            Cleaned Words
                        </div>
                        <div class="text-2xl font-bold text-emerald-500">
                            {{ cleanedWords }}
                        </div>
                    </div>
                </div>

                <!-- Additional Metrics -->
                <div
                    v-if="formattedMetrics.length > 0"
                    class="rounded-xl border bg-card p-4"
                >
                    <h2 class="mb-3 font-semibold">Processing Metrics</h2>
                    <div class="flex flex-wrap gap-4">
                        <div
                            v-for="metric in formattedMetrics"
                            :key="metric.name"
                            class="text-sm"
                        >
                            <span class="text-muted-foreground"
                                >{{ metric.name }}:</span
                            >
                            <span class="ml-1 font-medium">{{
                                metric.value
                            }}</span>
                        </div>
                    </div>
                </div>

                <!-- Removals Summary -->
                <div
                    v-if="
                        audioSample.removals && audioSample.removals.length > 0
                    "
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

                <!-- View Toggle with Copy Buttons -->
                <div
                    v-if="hasRawText || hasCleanedText"
                    class="flex flex-col gap-4 border-b pb-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex gap-2">
                        <button
                            v-if="hasCleanedText"
                            @click="activeView = 'cleaned'"
                            :class="[
                                '-mb-px border-b-2 px-4 py-2 font-medium transition-colors',
                                activeView === 'cleaned'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ]"
                        >
                            Cleaned Text
                        </button>
                        <button
                            v-if="hasRawText"
                            @click="activeView = 'original'"
                            :class="[
                                '-mb-px border-b-2 px-4 py-2 font-medium transition-colors',
                                activeView === 'original'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ]"
                        >
                            Original Text
                        </button>
                        <button
                            v-if="hasRawText && hasCleanedText"
                            @click="activeView = 'side-by-side'"
                            :class="[
                                '-mb-px border-b-2 px-4 py-2 font-medium transition-colors',
                                activeView === 'side-by-side'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ]"
                        >
                            Side by Side
                        </button>
                        <button
                            v-if="hasRawText && hasCleanedText"
                            @click="activeView = 'diff'"
                            :class="[
                                '-mb-px border-b-2 px-4 py-2 font-medium transition-colors',
                                activeView === 'diff'
                                    ? 'border-primary text-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ]"
                        >
                            Diff View
                        </button>
                    </div>
                    <div class="flex gap-2">
                        <button
                            v-if="hasRawText"
                            @click="copyOriginalText"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors hover:bg-muted disabled:opacity-50"
                        >
                            <CheckIcon
                                v-if="copiedOriginal"
                                class="h-4 w-4 text-green-500"
                            />
                            <ClipboardDocumentIcon v-else class="h-4 w-4" />
                            {{ copiedOriginal ? 'Copied!' : 'Copy Original' }}
                        </button>
                        <button
                            v-if="hasCleanedText"
                            @click="copyCleanedText"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-emerald-700 disabled:opacity-50"
                        >
                            <CheckIcon v-if="copiedCleaned" class="h-4 w-4" />
                            <ClipboardDocumentIcon v-else class="h-4 w-4" />
                            {{ copiedCleaned ? 'Copied!' : 'Copy Cleaned' }}
                        </button>
                    </div>
                </div>

                <!-- Cleaned Text View (with inline edit) -->
                <div
                    v-if="activeView === 'cleaned' && hasCleanedText"
                    class="overflow-hidden rounded-xl border bg-card"
                >
                    <div
                        class="flex items-center justify-between border-b bg-emerald-50 px-4 py-2 dark:bg-emerald-900/20"
                    >
                        <h3
                            class="font-semibold text-emerald-700 dark:text-emerald-400"
                        >
                            Cleaned Text
                        </h3>
                        <div class="flex gap-2">
                            <button
                                v-if="!isEditing"
                                @click="startEditing"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1 text-sm font-medium hover:bg-muted"
                            >
                                <PencilIcon class="h-4 w-4" />
                                Edit
                            </button>
                            <template v-else>
                                <button
                                    @click="cancelEditing"
                                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1 text-sm font-medium hover:bg-muted"
                                >
                                    <XMarkIcon class="h-4 w-4" />
                                    Cancel
                                </button>
                                <button
                                    @click="saveEdit"
                                    :disabled="updateForm.processing"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                                >
                                    <CheckIcon class="h-4 w-4" />
                                    Save
                                </button>
                            </template>
                        </div>
                    </div>
                    <div class="max-h-[600px] min-h-64 overflow-y-auto p-4">
                        <textarea
                            v-if="isEditing"
                            v-model="editedText"
                            dir="rtl"
                            class="h-96 w-full resize-y rounded-lg border bg-transparent p-2 font-mono text-sm"
                        ></textarea>
                        <pre
                            v-else
                            class="font-mono text-sm whitespace-pre-wrap"
                            dir="rtl"
                            >{{ audioSample.reference_text_clean }}</pre
                        >
                    </div>
                </div>

                <!-- Original Text View -->
                <div
                    v-else-if="activeView === 'original' && hasRawText"
                    class="max-h-[600px] min-h-64 overflow-y-auto rounded-xl border bg-card p-4"
                >
                    <pre
                        class="font-mono text-sm whitespace-pre-wrap"
                        dir="rtl"
                        >{{ audioSample.reference_text_raw }}</pre
                    >
                </div>

                <!-- Side by Side View -->
                <div
                    v-else-if="activeView === 'side-by-side'"
                    class="grid gap-4 md:grid-cols-2"
                >
                    <div
                        class="flex max-h-[600px] min-h-64 flex-col overflow-hidden rounded-xl border bg-card"
                    >
                        <div
                            class="border-b bg-red-50 px-4 py-2 dark:bg-red-900/20"
                        >
                            <h3
                                class="font-semibold text-red-700 dark:text-red-400"
                            >
                                Original
                            </h3>
                        </div>
                        <div class="flex-1 overflow-y-auto p-4">
                            <pre
                                class="font-mono text-sm whitespace-pre-wrap"
                                dir="rtl"
                                >{{
                                    audioSample.reference_text_raw ||
                                    'No original text'
                                }}</pre
                            >
                        </div>
                    </div>
                    <div
                        class="flex max-h-[600px] min-h-64 flex-col overflow-hidden rounded-xl border bg-card"
                    >
                        <div
                            class="border-b bg-emerald-50 px-4 py-2 dark:bg-emerald-900/20"
                        >
                            <h3
                                class="font-semibold text-emerald-700 dark:text-emerald-400"
                            >
                                Cleaned
                            </h3>
                        </div>
                        <div class="flex-1 overflow-y-auto p-4">
                            <pre
                                class="font-mono text-sm whitespace-pre-wrap"
                                dir="rtl"
                                >{{
                                    audioSample.reference_text_clean ||
                                    'No cleaned text'
                                }}</pre
                            >
                        </div>
                    </div>
                </div>

                <!-- Diff View -->
                <div v-else-if="activeView === 'diff'" class="space-y-4">
                    <!-- Diff Stats -->
                    <div class="grid grid-cols-3 gap-4">
                        <div class="rounded-xl border bg-card p-4">
                            <div class="text-sm text-muted-foreground">
                                Chars Removed
                            </div>
                            <div class="text-2xl font-bold text-red-500">
                                {{ diffStats.removed.toLocaleString() }}
                            </div>
                        </div>
                        <div class="rounded-xl border bg-card p-4">
                            <div class="text-sm text-muted-foreground">
                                Chars Added
                            </div>
                            <div class="text-2xl font-bold text-teal-500">
                                {{ diffStats.added.toLocaleString() }}
                            </div>
                        </div>
                        <div class="rounded-xl border bg-card p-4">
                            <div class="text-sm text-muted-foreground">
                                Chars Unchanged
                            </div>
                            <div class="text-2xl font-bold text-emerald-500">
                                {{ diffStats.unchanged.toLocaleString() }}
                            </div>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="flex items-center justify-end gap-4 text-sm">
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded bg-red-500/20 px-2 py-0.5 text-red-600 line-through dark:text-red-400"
                                >removed</span
                            >
                            <span>Removed</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded bg-teal-500/20 px-2 py-0.5 text-teal-600 dark:text-teal-400"
                                >added</span
                            >
                            <span>Added</span>
                        </div>
                    </div>

                    <!-- Character-level Diff View -->
                    <div class="overflow-hidden rounded-xl border bg-card">
                        <div
                            class="max-h-[600px] overflow-y-auto p-4"
                            dir="rtl"
                        >
                            <div
                                class="font-mono text-sm leading-relaxed whitespace-pre-wrap"
                            >
                                <template
                                    v-for="(segment, idx) in charDiff"
                                    :key="idx"
                                >
                                    <span
                                        v-if="segment.type === 'removed'"
                                        class="bg-red-500/20 text-red-600 line-through decoration-red-500/50 dark:text-red-400"
                                        >{{ segment.text }}</span
                                    >
                                    <span
                                        v-else-if="segment.type === 'added'"
                                        class="bg-teal-500/20 text-teal-600 dark:text-teal-400"
                                        >{{ segment.text }}</span
                                    >
                                    <span v-else>{{ segment.text }}</span>
                                </template>
                            </div>
                            <div
                                v-if="charDiff.length === 0"
                                class="p-8 text-center text-muted-foreground"
                            >
                                No differences found
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==========================================
                 ASR Transcription Section (Benchmark Ready only)
                 ========================================== -->
                <div v-if="isValidated" class="mt-6 space-y-6">
                    <!-- Transcription Section Header -->
                    <div class="flex items-center justify-between">
                        <div>
                            <h2
                                class="flex items-center gap-2 text-xl font-semibold"
                            >
                                <MicrophoneIcon class="h-5 w-5" />
                                ASR Transcriptions
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Run ASR models against this sample to benchmark
                                performance
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <button
                                @click="
                                    showManualEntryForm = !showManualEntryForm
                                "
                                class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 font-medium hover:bg-muted"
                            >
                                <PlusIcon class="h-4 w-4" />
                                Add Manual Entry
                            </button>
                            <button
                                @click="
                                    showTranscriptionForm =
                                        !showTranscriptionForm
                                "
                                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                            >
                                <MicrophoneIcon class="h-4 w-4" />
                                Run ASR
                            </button>
                        </div>
                    </div>

                    <!-- ASR Transcription Form -->
                    <div
                        v-if="showTranscriptionForm"
                        class="rounded-xl border bg-card p-6"
                    >
                        <h3 class="mb-4 font-semibold">
                            Run ASR Transcription
                        </h3>
                        <form
                            @submit.prevent="submitTranscription"
                            class="space-y-4"
                        >
                            <div class="grid gap-4 md:grid-cols-2">
                                <!-- Provider Selection -->
                                <div>
                                    <label
                                        class="mb-1 block text-sm font-medium"
                                        >ASR Provider</label
                                    >
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
                                            <span v-if="!provider.hasCredential"
                                                >(No API Key)</span
                                            >
                                        </option>
                                    </select>
                                    <p
                                        v-if="
                                            asrProviders[
                                                transcribeForm.provider
                                            ]?.description
                                        "
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{
                                            asrProviders[
                                                transcribeForm.provider
                                            ].description
                                        }}
                                    </p>
                                </div>

                                <!-- Model Selection -->
                                <div>
                                    <label
                                        class="mb-1 block text-sm font-medium"
                                        >Model</label
                                    >
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

                            <!-- Notes -->
                            <div>
                                <label class="mb-1 block text-sm font-medium"
                                    >Notes (optional)</label
                                >
                                <textarea
                                    v-model="transcribeForm.notes"
                                    rows="2"
                                    class="w-full rounded-lg border bg-background px-3 py-2"
                                    placeholder="Any notes about this transcription run..."
                                ></textarea>
                            </div>

                            <!-- Provider Warning -->
                            <div
                                v-if="
                                    asrProviderOptions.find(
                                        (p) =>
                                            p.id === transcribeForm.provider &&
                                            !p.hasCredential,
                                    )
                                "
                                class="flex items-center gap-2 rounded-lg bg-yellow-50 p-3 text-sm text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-200"
                            >
                                <ExclamationTriangleIcon class="h-5 w-5" />
                                No API key configured for this provider. Please
                                add credentials in Settings.
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
                                        !asrProviderOptions.find(
                                            (p) =>
                                                p.id ===
                                                transcribeForm.provider,
                                        )?.hasCredential
                                    "
                                    class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                >
                                    <span v-if="transcribeForm.processing"
                                        >Processing...</span
                                    >
                                    <span v-else>Start Transcription</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Manual Entry Form -->
                    <div
                        v-if="showManualEntryForm"
                        class="rounded-xl border bg-card p-6"
                    >
                        <h3 class="mb-4 font-semibold">
                            Add Manual Benchmark Entry
                        </h3>
                        <p class="mb-4 text-sm text-muted-foreground">
                            Manually enter transcription results from an
                            external ASR system for benchmarking.
                        </p>
                        <form
                            @submit.prevent="submitManualTranscription"
                            class="space-y-4"
                        >
                            <div class="grid gap-4 md:grid-cols-2">
                                <!-- Model Name -->
                                <div>
                                    <label
                                        class="mb-1 block text-sm font-medium"
                                        >Model Name *</label
                                    >
                                    <input
                                        v-model="
                                            manualTranscriptionForm.model_name
                                        "
                                        type="text"
                                        required
                                        class="w-full rounded-lg border bg-background px-3 py-2"
                                        placeholder="e.g., google/chirp, azure/whisper-large"
                                    />
                                </div>

                                <!-- Model Version -->
                                <div>
                                    <label
                                        class="mb-1 block text-sm font-medium"
                                        >Model Version</label
                                    >
                                    <input
                                        v-model="
                                            manualTranscriptionForm.model_version
                                        "
                                        type="text"
                                        class="w-full rounded-lg border bg-background px-3 py-2"
                                        placeholder="e.g., v2, 2024-01"
                                    />
                                </div>
                            </div>

                            <!-- Hypothesis Text -->
                            <div>
                                <label class="mb-1 block text-sm font-medium"
                                    >Transcription Output *</label
                                >
                                <textarea
                                    v-model="
                                        manualTranscriptionForm.hypothesis_text
                                    "
                                    required
                                    rows="4"
                                    dir="rtl"
                                    class="w-full rounded-lg border bg-background px-3 py-2 font-mono"
                                    placeholder="Paste the ASR output here..."
                                ></textarea>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    WER and CER will be calculated automatically
                                    against the reference text.
                                </p>
                            </div>

                            <!-- Notes -->
                            <div>
                                <label class="mb-1 block text-sm font-medium"
                                    >Notes</label
                                >
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
                                    :disabled="
                                        manualTranscriptionForm.processing
                                    "
                                    class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                >
                                    <span
                                        v-if="
                                            manualTranscriptionForm.processing
                                        "
                                        >Saving...</span
                                    >
                                    <span v-else>Add Entry</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Transcriptions List -->
                    <div
                        v-if="transcriptions.length > 0"
                        class="overflow-hidden rounded-xl border bg-card"
                    >
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-muted/50">
                                    <tr>
                                        <th
                                            class="px-4 py-3 text-left text-sm font-medium"
                                        >
                                            Model
                                        </th>
                                        <th
                                            class="px-4 py-3 text-left text-sm font-medium"
                                        >
                                            Source
                                        </th>
                                        <th
                                            class="px-4 py-3 text-center text-sm font-medium"
                                        >
                                            WER
                                        </th>
                                        <th
                                            class="px-4 py-3 text-center text-sm font-medium"
                                        >
                                            CER
                                        </th>
                                        <th
                                            class="px-4 py-3 text-center text-sm font-medium"
                                        >
                                            Errors
                                        </th>
                                        <th
                                            class="px-4 py-3 text-left text-sm font-medium"
                                        >
                                            Notes
                                        </th>
                                        <th
                                            class="px-4 py-3 text-right text-sm font-medium"
                                        >
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="transcription in transcriptions"
                                        :key="transcription.id"
                                        class="hover:bg-muted/30"
                                    >
                                        <td class="px-4 py-3">
                                            <div class="font-medium">
                                                {{ transcription.model_name }}
                                            </div>
                                            <div
                                                v-if="
                                                    transcription.model_version
                                                "
                                                class="text-xs text-muted-foreground"
                                            >
                                                v{{
                                                    transcription.model_version
                                                }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                :class="[
                                                    'rounded-full px-2 py-0.5 text-xs font-medium',
                                                    getSourceColor(
                                                        transcription.source,
                                                    ),
                                                ]"
                                            >
                                                {{
                                                    transcription.source ===
                                                    'generated'
                                                        ? 'API'
                                                        : 'Manual'
                                                }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span
                                                :class="[
                                                    'font-mono font-semibold',
                                                    getWerColor(
                                                        transcription.wer,
                                                    ),
                                                ]"
                                            >
                                                {{
                                                    formatErrorRate(
                                                        transcription.wer,
                                                    )
                                                }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span
                                                class="font-mono text-muted-foreground"
                                            >
                                                {{
                                                    formatErrorRate(
                                                        transcription.cer,
                                                    )
                                                }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <div
                                                class="text-xs text-muted-foreground"
                                            >
                                                <span title="Substitutions"
                                                    >S:{{
                                                        transcription.substitutions
                                                    }}</span
                                                >
                                                <span class="mx-1">·</span>
                                                <span title="Insertions"
                                                    >I:{{
                                                        transcription.insertions
                                                    }}</span
                                                >
                                                <span class="mx-1">·</span>
                                                <span title="Deletions"
                                                    >D:{{
                                                        transcription.deletions
                                                    }}</span
                                                >
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
                                            <span
                                                v-else
                                                class="text-muted-foreground/50"
                                                >—</span
                                            >
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <button
                                                @click="
                                                    deleteTranscription(
                                                        transcription.id,
                                                    )
                                                "
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

                    <!-- Empty State -->
                    <div
                        v-else
                        class="rounded-xl border border-dashed bg-card p-8 text-center"
                    >
                        <MicrophoneIcon
                            class="mx-auto h-12 w-12 text-muted-foreground/50"
                        />
                        <h3 class="mt-4 font-semibold">
                            No Transcriptions Yet
                        </h3>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Run an ASR model or add a manual entry to start
                            benchmarking this sample.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
