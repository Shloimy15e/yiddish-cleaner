<script setup lang="ts">
import {
    Listbox,
    ListboxButton,
    ListboxOption,
    ListboxOptions,
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import {
    InformationCircleIcon,
    SparklesIcon,
    CpuChipIcon,
    CheckCircleIcon,
    XCircleIcon,
    LinkIcon,
    ArrowPathIcon,
    DocumentTextIcon,
    PencilIcon,
    TrashIcon,
    AdjustmentsHorizontalIcon,
    AcademicCapIcon,
} from '@heroicons/vue/24/outline';
import { Loader } from 'lucide-vue-next';

import AudioPlayer from '@/components/AudioPlayer.vue';
import TranscriptionWordReview from '@/components/transcriptions/TranscriptionWordReview.vue';
import type { BreadcrumbItem } from '@/types';
import type { AudioMedia } from '@/types/audio-samples';
import type { Preset } from '@/types/audio-samples';
import type { LlmModel, LlmProvider, DiffSegment, AlignmentItem } from '@/types/transcription-show';
import type { BaseTranscription, AsrTranscription, Transcription, WordReviewStats } from '@/types/transcriptions';

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
        const crumbs: BreadcrumbItem[] = [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Base Transcriptions', href: '/transcriptions' },
            { title: baseTranscription.value?.name || `Transcription #${props.transcription.id}`, href: '#' },
        ];
        return crumbs;
    } else {
        return [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Audio Samples', href: '/audio-samples' },
            { title: props.audioSample?.name || 'Audio Sample', href: `/audio-samples/${props.audioSample?.id}` },
            { title: 'ASR Transcription', href: '#' },
        ];
    }
});

// ==================== BASE TRANSCRIPTION STATE ====================

// View state for base transcription
const activeView = ref<'cleaned' | 'original' | 'side-by-side' | 'diff'>('cleaned');
const diffViewMode = ref<'split' | 'unified'>('unified');
const isEditing = ref(false);
const editedText = ref('');
const showCleanForm = ref(false);

// Cleaning form
const cleanForm = useForm({
    preset: baseTranscription.value?.cleaning_preset ?? 'titles_only',
    mode: 'rule' as 'rule' | 'llm',
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
});

// LLM providers state
const llmProviders = ref<Record<string, LlmProvider>>({});
const loadingModels = ref(false);
const providerModels = ref<LlmModel[]>([]);

// Preset options
const presetOptions = computed(() =>
    props.presets
        ? Object.entries(props.presets).map(([key, value]) => ({
            id: key,
            name: value.name,
            description: value.description,
        }))
        : []
);

const availableLlmProviders = computed(() =>
    Object.entries(llmProviders.value).filter(([, value]) => value.has_credential)
);

// Provider options
const providerOptions = computed(() =>
    availableLlmProviders.value.map(([key, value]) => ({
        id: key,
        name: value.name,
        hasCredential: value.has_credential,
    }))
);

const getFirstAvailableProvider = () =>
    availableLlmProviders.value[0]?.[0] ?? null;

// Fetch LLM providers
const fetchProviders = async () => {
    try {
        const response = await fetch('/api/llm/providers');
        llmProviders.value = await response.json();

        const firstAvailable = getFirstAvailableProvider();
        if (firstAvailable) {
            if (!llmProviders.value[cleanForm.llm_provider]?.has_credential) {
                cleanForm.llm_provider = firstAvailable;
            }
            await fetchModelsForProvider(cleanForm.llm_provider);
        } else {
            providerModels.value = [];
            cleanForm.llm_model = '';
        }
    } catch (error) {
        console.error('Failed to fetch LLM providers:', error);
    }
};

// Fetch models for provider
const fetchModelsForProvider = async (provider: string) => {
    if (!provider) {
        providerModels.value = [];
        cleanForm.llm_model = '';
        return;
    }

    loadingModels.value = true;
    try {
        const response = await fetch(`/api/llm/providers/${provider}/models`);
        const payload = await response.json();
        const models = (payload?.models ?? []) as LlmModel[];
        const defaultModel = payload?.default as string | undefined;

        providerModels.value = models;
        cleanForm.llm_model =
            (defaultModel && models.some((model) => model.id === defaultModel)
                ? defaultModel
                : models[0]?.id) ?? '';
    } catch (error) {
        console.error('Failed to fetch models:', error);
        providerModels.value = [];
        cleanForm.llm_model = '';
    } finally {
        loadingModels.value = false;
    }
};

// Watch provider changes
watch(
    () => cleanForm.llm_provider,
    (newProvider) => fetchModelsForProvider(newProvider)
);

onMounted(() => {
    if (isBase.value) {
        fetchProviders();
    }
});

// Submit cleaning
const submitClean = () => {
    cleanForm.post(`/transcriptions/${props.transcription.id}/clean`, {
        preserveScroll: true,
        onSuccess: () => {
            showCleanForm.value = false;
        },
    });
};

// Edit form
const updateForm = useForm({
    text_clean: '',
});

// Name editing
const isEditingName = ref(false);
const nameForm = useForm({
    name: baseTranscription.value?.name || '',
});

const startEditingName = () => {
    nameForm.name = baseTranscription.value?.name || '';
    isEditingName.value = true;
};

const cancelEditingName = () => {
    isEditingName.value = false;
    nameForm.name = baseTranscription.value?.name || '';
};

const saveName = () => {
    nameForm.patch(`/transcriptions/${props.transcription.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            isEditingName.value = false;
        },
    });
};

const startEditing = () => {
    editedText.value = decodedCleanText.value || '';
    isEditing.value = true;
};

const cancelEditing = () => {
    isEditing.value = false;
    editedText.value = '';
};

const saveEdit = () => {
    updateForm.text_clean = editedText.value;
    updateForm.patch(`/transcriptions/${props.transcription.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            isEditing.value = false;
            editedText.value = '';
        },
    });
};

// Validation form
const validateForm = useForm({
    notes: '',
});

const submitValidate = () => {
    validateForm.post(`/transcriptions/${props.transcription.id}/validate`, {
        preserveScroll: true,
    });
};

const submitUnvalidate = () => {
    router.delete(`/transcriptions/${props.transcription.id}/validate`, {
        preserveScroll: true,
    });
};

// Link modal state
const showLinkModal = ref(false);

const handleLinked = () => {
    showLinkModal.value = false;
    // Page will refresh automatically via Inertia
};

// Link form
const linkForm = useForm({
    audio_sample_id: null as number | null,
});

const submitUnlink = () => {
    router.delete(`/transcriptions/${props.transcription.id}/link`, {
        preserveScroll: true,
    });
};

// Delete
const deleteTranscription = () => {
    if (!confirm('Delete this transcription? This cannot be undone.')) return;
    router.delete(`/transcriptions/${props.transcription.id}`);
};

const decodedRawText = computed(() =>
    baseTranscription.value?.text_raw
        ? decodeHtmlEntities(baseTranscription.value.text_raw)
        : ''
);

const decodedCleanText = computed(() =>
    baseTranscription.value?.text_clean
        ? decodeHtmlEntities(baseTranscription.value.text_clean)
        : ''
);

// Editable diff state
const isDiffEditing = ref(false);
const diffEditedText = ref('');
const debouncedEditedText = ref('');
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

// Debounce the edited text to avoid recomputing diff on every keystroke
watch(diffEditedText, (newValue) => {
    console.log('Diff edited text changed, starting debounce');
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(() => {
        debouncedEditedText.value = newValue;
    }, 300);
});

// Live diff - compares original with debounced edited text when editing
const liveDiff = computed(() => {
    if (!baseTranscription.value?.text_raw) {
        return [];
    }
    const compareText = isDiffEditing.value
        ? debouncedEditedText.value
        : decodedCleanText.value;

    if (!compareText) {
        return [];
    }

    return generateDiff(decodedRawText.value, compareText);
});

// Static diff for display (uses saved clean text)
const charDiff = computed(() => {
    if (!baseTranscription.value?.text_raw || !baseTranscription.value?.text_clean) {
        return [];
    }
    return generateDiff(decodedRawText.value, decodedCleanText.value);
});

// Diff statistics - uses live diff when editing
const diffStats = computed(() => {
    const diffToUse = isDiffEditing.value ? liveDiff.value : charDiff.value;
    return calculateDiffStats(diffToUse);
});

const startDiffEditing = () => {
    diffEditedText.value = decodedCleanText.value || '';
    debouncedEditedText.value = decodedCleanText.value || '';
    isDiffEditing.value = true;
};

const cancelDiffEditing = () => {
    isDiffEditing.value = false;
    diffEditedText.value = '';
    debouncedEditedText.value = '';
    if (debounceTimer) {
        clearTimeout(debounceTimer);
        debounceTimer = null;
    }
};

const saveDiffEdit = () => {
    updateForm.text_clean = diffEditedText.value;
    updateForm.patch(`/transcriptions/${props.transcription.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            isDiffEditing.value = false;
            diffEditedText.value = '';
        },
    });
};

// ==================== ASR TRANSCRIPTION STATE ====================

const viewMode = ref<'alignment' | 'side-by-side'>('alignment');

// Word Review State
const audioPlayerRef = ref<InstanceType<typeof AudioPlayer> | null>(null);
const wordReviewStats = ref<WordReviewStats | null>(null);
const showWordReview = ref(true);

// Training flag toggle
const trainingFlagForm = useForm({});
const toggleTrainingFlag = () => {
    if (!props.audioSample || !asrTranscription.value) return;

    trainingFlagForm.post(`/api/transcriptions/${props.transcription.id}/words/toggle-training-flag`, {
        preserveScroll: true,
    });
};

const handleWordReviewStats = (stats: WordReviewStats) => {
    wordReviewStats.value = stats;
};

const handleAlignmentStarted = () => {
    // Show a success message - the component will handle polling
    console.log('Alignment job started');
};

// WER Range Selection
const showRangeModal = ref(false);
const rangeForm = useForm({
    ref_start: null as number | null,
    ref_end: null as number | null,
    hyp_start: null as number | null,
    hyp_end: null as number | null,
});

// Interactive selection state
type SelectionMode = 'ref-start' | 'ref-end' | 'hyp-start' | 'hyp-end' | null;
const selectionMode = ref<SelectionMode>(null);

// Tokenized words for display
const refWords = computed(() => tokenize(referenceText.value));
const hypWords = computed(() => tokenize(hypothesisText.value));

// Get total word counts for range validation
const totalRefWords = computed(() => refWords.value.length);
const totalHypWords = computed(() => hypWords.value.length);

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

// Check if word is in selected range
const isWordInRefRange = (index: number) => {
    const start = rangeForm.ref_start ?? 0;
    const end = rangeForm.ref_end ?? (totalRefWords.value - 1);
    return index >= start && index <= end;
};

const isWordInHypRange = (index: number) => {
    const start = rangeForm.hyp_start ?? 0;
    const end = rangeForm.hyp_end ?? (totalHypWords.value - 1);
    return index >= start && index <= end;
};

// Check if word is a boundary
const isRefStart = (index: number) => index === (rangeForm.ref_start ?? 0);
const isRefEnd = (index: number) => index === (rangeForm.ref_end ?? (totalRefWords.value - 1));
const isHypStart = (index: number) => index === (rangeForm.hyp_start ?? 0);
const isHypEnd = (index: number) => index === (rangeForm.hyp_end ?? (totalHypWords.value - 1));

// Handle word click
const handleRefWordClick = (index: number) => {
    if (selectionMode.value === 'ref-start') {
        rangeForm.ref_start = index;
        // Auto-adjust end if needed
        if (rangeForm.ref_end !== null && rangeForm.ref_end < index) {
            rangeForm.ref_end = index;
        }
        selectionMode.value = null;
    } else if (selectionMode.value === 'ref-end') {
        rangeForm.ref_end = index;
        // Auto-adjust start if needed
        if (rangeForm.ref_start !== null && rangeForm.ref_start > index) {
            rangeForm.ref_start = index;
        }
        selectionMode.value = null;
    }
};

const handleHypWordClick = (index: number) => {
    if (selectionMode.value === 'hyp-start') {
        rangeForm.hyp_start = index;
        // Auto-adjust end if needed
        if (rangeForm.hyp_end !== null && rangeForm.hyp_end < index) {
            rangeForm.hyp_end = index;
        }
        selectionMode.value = null;
    } else if (selectionMode.value === 'hyp-end') {
        rangeForm.hyp_end = index;
        // Auto-adjust start if needed
        if (rangeForm.hyp_start !== null && rangeForm.hyp_start > index) {
            rangeForm.hyp_start = index;
        }
        selectionMode.value = null;
    }
};

// Get word class for styling
const getRefWordClass = (index: number) => {
    const inRange = isWordInRefRange(index);
    const isStart = isRefStart(index);
    const isEnd = isRefEnd(index);
    const isSelecting = selectionMode.value === 'ref-start' || selectionMode.value === 'ref-end';
    
    return [
        'inline-block rounded px-1 py-0.5 text-sm cursor-pointer transition-all',
        isSelecting ? 'hover:ring-2 hover:ring-primary' : '',
        inRange ? 'bg-primary/20' : 'bg-muted/50 opacity-50',
        isStart ? 'ring-2 ring-green-500' : '',
        isEnd ? 'ring-2 ring-red-500' : '',
        isStart && isEnd ? 'ring-2 ring-purple-500' : '',
    ];
};

const getHypWordClass = (index: number) => {
    const inRange = isWordInHypRange(index);
    const isStart = isHypStart(index);
    const isEnd = isHypEnd(index);
    const isSelecting = selectionMode.value === 'hyp-start' || selectionMode.value === 'hyp-end';
    
    return [
        'inline-block rounded px-1 py-0.5 text-sm cursor-pointer transition-all',
        isSelecting ? 'hover:ring-2 hover:ring-primary' : '',
        inRange ? 'bg-primary/20' : 'bg-muted/50 opacity-50',
        isStart ? 'ring-2 ring-green-500' : '',
        isEnd ? 'ring-2 ring-red-500' : '',
        isStart && isEnd ? 'ring-2 ring-purple-500' : '',
    ];
};

const openRangeModal = () => {
    const t = asrTranscription.value;
    rangeForm.ref_start = t?.wer_ref_start ?? null;
    rangeForm.ref_end = t?.wer_ref_end ?? null;
    rangeForm.hyp_start = t?.wer_hyp_start ?? null;
    rangeForm.hyp_end = t?.wer_hyp_end ?? null;
    selectionMode.value = null;
    showRangeModal.value = true;
};

const submitRangeRecalculate = () => {
    if (!props.audioSample || !asrTranscription.value) return;
    
    rangeForm.post(`/audio-samples/${props.audioSample.id}/transcriptions/${asrTranscription.value.id}/recalculate`, {
        preserveScroll: true,
        onSuccess: () => {
            showRangeModal.value = false;
            selectionMode.value = null;
        },
    });
};

const resetRange = () => {
    rangeForm.ref_start = null;
    rangeForm.ref_end = null;
    rangeForm.hyp_start = null;
    rangeForm.hyp_end = null;
    selectionMode.value = null;
};

// Get reference text for ASR comparison
const referenceText = computed(() => {
    if (isAsr.value && props.audioSample?.base_transcription?.text_clean) {
        return decodeHtmlEntities(props.audioSample.base_transcription.text_clean);
    }
    return '';
});

const hypothesisText = computed(() => asrTranscription.value?.hypothesis_text ?? '');

const alignment = computed(() => {
    if (!referenceText.value.trim() || !hypothesisText.value.trim()) return [];
    return buildAlignmentFromDiff(referenceText.value, hypothesisText.value);
});

const chunkedAlignment = computed(() => {
    const perRow = 15;
    const chunks: AlignmentItem[][] = [];
    for (let i = 0; i < alignment.value.length; i += perRow) {
        chunks.push(alignment.value.slice(i, i + perRow));
    }
    return chunks;
});

// ==================== SHARED HELPERS ====================
// formatStatus and statusClass are imported from @/lib/transcriptionUtils
</script>

<template>

    <Head :title="isBase ? (baseTranscription?.name || 'Base Transcription') : 'ASR Transcription'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">

            <!-- ==================== BASE TRANSCRIPTION VIEW ==================== -->
            <template v-if="isBase && baseTranscription">
                <!-- Header -->
                <div class="flex flex-col gap-4 rounded-xl border bg-card p-6">
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div>
                            <!-- Editable name -->
                            <div v-if="isEditingName" class="flex items-center gap-2">
                                <input
                                    v-model="nameForm.name"
                                    type="text"
                                    class="rounded-lg border border-border bg-background px-3 py-1.5 text-xl font-bold focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    @keyup.enter="saveName"
                                    @keyup.escape="cancelEditingName"
                                />
                                <button
                                    @click="saveName"
                                    :disabled="nameForm.processing"
                                    class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                >
                                    Save
                                </button>
                                <button
                                    @click="cancelEditingName"
                                    class="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted"
                                >
                                    Cancel
                                </button>
                            </div>
                            <h1 v-else class="group flex items-center gap-2 text-2xl font-bold">
                                {{ baseTranscription.name || `Transcription #${baseTranscription.id}` }}
                                <button
                                    @click="startEditingName"
                                    class="rounded p-1 text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-foreground group-hover:opacity-100"
                                    title="Edit name"
                                >
                                    <PencilIcon class="h-4 w-4" />
                                </button>
                            </h1>
                            <p class="text-sm text-muted-foreground">Base Transcription</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', statusClass(baseTranscription.status)]">
                                {{ formatStatus(baseTranscription.status) }}
                            </span>
                            <span v-if="baseTranscription.validated_at" class="flex items-center gap-1 text-emerald-600 text-sm">
                                <CheckCircleIcon class="h-4 w-4" />
                                Validated
                            </span>
                        </div>
                    </div>

                    <!-- Info Grid -->
                    <div class="grid gap-4 md:grid-cols-5">
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">Source</div>
                            <div class="font-medium capitalize">{{ baseTranscription.source }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">Clean Rate</div>
                            <div class="font-mono font-semibold">
                                {{ baseTranscription.clean_rate !== null ? `${baseTranscription.clean_rate}%` : '—' }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">Linked Audio</div>
                            <div v-if="baseTranscription.audio_sample">
                                <Link :href="`/audio-samples/${baseTranscription.audio_sample.id}`" class="flex items-center gap-1 text-primary hover:underline">
                                <LinkIcon class="h-4 w-4" />
                                {{ baseTranscription.audio_sample.name }}
                                </Link>
                            </div>
                            <div v-else class="text-muted-foreground">Not linked</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">Created By</div>
                            <div class="text-sm">
                                {{ formatCreatedBy(baseTranscription?.user, undefined) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">Created</div>
                            <div class="text-sm">{{ formatDate(baseTranscription.created_at) }}</div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-wrap gap-2 pt-2 border-t">
                        <button v-if="!baseTranscription.audio_sample_id" @click="showLinkModal = true" class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm hover:bg-muted">
                            <LinkIcon class="h-4 w-4" />
                            Link to Audio Sample
                        </button>
                        <button v-if="baseTranscription.audio_sample_id" @click="submitUnlink" class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm hover:bg-muted">
                            <XCircleIcon class="h-4 w-4" />
                            Unlink
                        </button>
                        <button @click="deleteTranscription" class="flex items-center gap-1 rounded-lg border border-destructive text-destructive px-3 py-1.5 text-sm hover:bg-destructive/10">
                            <TrashIcon class="h-4 w-4" />
                            Delete
                        </button>
                    </div>
                </div>

                <!-- Cleaning Section (if needs cleaning) -->
                <div v-if="baseTranscription.text_raw && !baseTranscription.text_clean && baseTranscription.status !== 'processing'" class="rounded-xl border-2 border-blue-200 bg-blue-50 p-6 dark:border-blue-800 dark:bg-blue-900/20">
                    <h2 class="mb-4 flex items-center gap-2 text-lg font-semibold">
                        <SparklesIcon class="h-5 w-5 text-blue-600" />
                        Clean This Transcript
                    </h2>
                    <form @submit.prevent="submitClean" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Cleaning Mode</label>
                            <div class="flex gap-2">
                                <button type="button" @click="cleanForm.mode = 'rule'" :class="[
                                    'flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 font-medium transition-colors',
                                    cleanForm.mode === 'rule' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                                ]">
                                    <CpuChipIcon class="h-4 w-4" />
                                    Rule-based
                                </button>
                                <button type="button" @click="cleanForm.mode = 'llm'" :class="[
                                    'flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 font-medium transition-colors',
                                    cleanForm.mode === 'llm' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                                ]">
                                    <SparklesIcon class="h-4 w-4" />
                                    AI (LLM)
                                </button>
                            </div>
                        </div>

                        <div v-if="cleanForm.mode === 'rule'">
                            <label class="mb-1 block text-sm font-medium">Cleaning Preset</label>
                            <Listbox v-model="cleanForm.preset">
                                <div class="relative">
                                    <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left">
                                        <span>{{presetOptions.find(p => p.id === cleanForm.preset)?.name || cleanForm.preset}}</span>
                                        <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                            <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                        </span>
                                    </ListboxButton>
                                    <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                        <ListboxOption v-for="preset in presetOptions" :key="preset.id" :value="preset.id" v-slot="{ active, selected }">
                                            <li :class="['relative cursor-pointer py-2 pl-10 pr-4', active ? 'bg-muted' : '']">
                                                <span :class="['block', selected ? 'font-medium' : '']">{{ preset.name }}</span>
                                                <span class="block text-xs text-muted-foreground">{{ preset.description }}</span>
                                                <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                    <CheckIcon class="h-5 w-5" />
                                                </span>
                                            </li>
                                        </ListboxOption>
                                    </ListboxOptions>
                                </div>
                            </Listbox>
                        </div>

                        <div v-if="cleanForm.mode === 'llm'" class="space-y-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium">LLM Provider</label>
                                <div v-if="providerOptions.length === 0" class="rounded-lg border border-amber-400/40 bg-amber-500/10 p-3 text-sm text-amber-700">
                                    No connected providers. Add credentials in
                                    <Link href="/settings/credentials" class="text-primary hover:underline">Settings</Link>.
                                </div>
                                <Listbox v-else v-model="cleanForm.llm_provider">
                                    <div class="relative">
                                        <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left">
                                            <span>{{providerOptions.find(p => p.id === cleanForm.llm_provider)?.name || cleanForm.llm_provider}}</span>
                                            <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                            </span>
                                        </ListboxButton>
                                        <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                            <ListboxOption v-for="provider in providerOptions" :key="provider.id" :value="provider.id" v-slot="{ active, selected }">
                                                <li :class="['relative cursor-pointer py-2 pl-10 pr-4', active ? 'bg-muted' : '']">
                                                    <span :class="['block', selected ? 'font-medium' : '']">{{ provider.name }}</span>
                                                    <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                        <CheckIcon class="h-5 w-5" />
                                                    </span>
                                                </li>
                                            </ListboxOption>
                                        </ListboxOptions>
                                    </div>
                                </Listbox>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Model</label>
                                <div v-if="loadingModels" class="rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground shadow-glow-sm flex items-center">
                                    <Loader class="h-5 w-5 animate-spin inline-block" />
                                    Loading models...
                                </div>
                                <div v-else-if="providerModels.length === 0" class="rounded-lg border border-amber-400/40 bg-amber-500/10 p-3 text-sm text-amber-700">
                                    No models available. Check provider credentials in
                                    <Link href="/settings/credentials" class="text-primary hover:underline">Settings</Link>.
                                </div>
                                <Listbox v-else v-model="cleanForm.llm_model">
                                    <div class="relative">
                                        <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left">
                                            <span>{{providerModels.find(m => m.id === cleanForm.llm_model)?.name || cleanForm.llm_model}}</span>
                                            <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                            </span>
                                        </ListboxButton>
                                        <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                            <ListboxOption v-for="model in providerModels" :key="model.id" :value="model.id" v-slot="{ active, selected }">
                                                <li :class="['relative cursor-pointer py-2 pl-10 pr-4', active ? 'bg-muted' : '']">
                                                    <span :class="['block', selected ? 'font-medium' : '']">{{ model.name }}</span>
                                                    <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                        <CheckIcon class="h-5 w-5" />
                                                    </span>
                                                </li>
                                            </ListboxOption>
                                        </ListboxOptions>
                                    </div>
                                </Listbox>
                            </div>
                        </div>

                        <button type="submit" :disabled="cleanForm.processing || (cleanForm.mode === 'llm' && (!providerOptions.length || !providerModels.length))" class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                            <ArrowPathIcon v-if="cleanForm.processing" class="h-4 w-4 animate-spin" />
                            <SparklesIcon v-else class="h-4 w-4" />
                            {{ cleanForm.processing ? 'Cleaning...' : 'Start Cleaning' }}
                        </button>
                    </form>
                </div>

                <!-- Processing indicator -->
                <div v-if="baseTranscription.status === 'processing'" class="overflow-hidden rounded-xl border border-border bg-card">
                    <div class="bg-blue-500/10 px-6 py-8 text-center">
                        <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-500/20">
                            <ArrowPathIcon class="h-7 w-7 animate-spin text-blue-600" />
                        </div>
                        <p class="text-lg font-semibold text-foreground">Cleaning in progress...</p>
                        <p class="mt-1 text-sm text-muted-foreground">This page will refresh when complete.</p>
                    </div>
                </div>

                <!-- Re-clean Section (if already cleaned) -->
                <div v-if="baseTranscription.text_clean && baseTranscription.status !== 'processing'" class="overflow-hidden rounded-xl border border-border bg-card transition-all hover:border-amber-500/50">
                    <div class="border-b border-border px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-warning/15">
                                    <ArrowPathIcon class="h-5 w-5 text-warning" />
                                </div>
                                <div>
                                    <h3 class="font-semibold text-foreground">Re-clean Transcript</h3>
                                    <p class="text-xs text-muted-foreground">Try different settings or AI model</p>
                                </div>
                            </div>
                            <button type="button" @click="showCleanForm = !showCleanForm" class="rounded-lg border px-4 py-2 text-sm font-medium transition-all" :class="showCleanForm
                                ? 'border-warning bg-warning/10 text-warning'
                                : 'border-border text-muted-foreground hover:border-warning hover:bg-warning/10 hover:text-warning'">
                                {{ showCleanForm ? 'Hide Options' : 'Show Options' }}
                            </button>
                        </div>
                    </div>

                    <div class="p-6">
                        <div v-if="!showCleanForm" class="py-4 text-center">
                            <p class="text-sm text-muted-foreground">
                                Not satisfied with the results? Click "Show Options" to try a different cleaning method or AI model.
                            </p>
                        </div>

                        <form v-else @submit.prevent="submitClean" class="space-y-5">
                            <div>
                                <label class="mb-2 block text-sm font-medium">Cleaning Mode</label>
                                <div class="flex gap-2">
                                    <button type="button" @click="cleanForm.mode = 'rule'" :class="[
                                        'flex-1 rounded-lg border-2 px-4 py-3 text-sm font-medium transition-all',
                                        cleanForm.mode === 'rule'
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border hover:border-primary/50 hover:bg-muted',
                                    ]">
                                        <span class="block">Rule-based</span>
                                        <span class="mt-0.5 block text-xs font-normal opacity-70">Fast & predictable</span>
                                    </button>
                                    <button type="button" @click="cleanForm.mode = 'llm'" :class="[
                                        'flex-1 rounded-lg border-2 px-4 py-3 text-sm font-medium transition-all',
                                        cleanForm.mode === 'llm'
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border hover:border-primary/50 hover:bg-muted',
                                    ]">
                                        <span class="block">AI (LLM)</span>
                                        <span class="mt-0.5 block text-xs font-normal opacity-70">Smart & flexible</span>
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium">Cleaning Preset</label>
                                <Listbox v-model="cleanForm.preset">
                                    <div class="relative">
                                        <ListboxButton class="relative w-full cursor-pointer rounded-lg border border-border bg-background px-4 py-2.5 text-left transition-colors hover:border-primary/50">
                                            <span class="block truncate">{{
                                                presetOptions.find((p) => p.id === cleanForm.preset)?.name || 'Select preset'
                                                }}</span>
                                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                            </span>
                                        </ListboxButton>
                                        <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg">
                                            <ListboxOption v-for="preset in presetOptions" :key="preset.id" :value="preset.id" v-slot="{ active, selected }">
                                                <li :class="[
                                                    'relative cursor-pointer select-none px-4 py-2.5',
                                                    active ? 'bg-muted' : '',
                                                ]">
                                                    <span :class="['block truncate', selected ? 'font-medium' : '']">
                                                        {{ preset.name }}
                                                    </span>
                                                    <span v-if="selected" class="absolute inset-y-0 right-0 flex items-center pr-3 text-primary">
                                                        <CheckIcon class="h-4 w-4" />
                                                    </span>
                                                </li>
                                            </ListboxOption>
                                        </ListboxOptions>
                                    </div>
                                </Listbox>
                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    {{presetOptions.find((p) => p.id === cleanForm.preset)?.description}}
                                </p>
                            </div>

                            <!-- LLM options -->
                            <div v-if="cleanForm.mode === 'llm'" class="space-y-5">
                                <div v-if="!providerOptions.length" class="rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
                                    <p class="text-sm text-amber-800 dark:text-amber-200">
                                        No LLM providers configured. Please add API credentials in
                                        <a href="/settings/credentials" class="font-medium underline hover:no-underline">Settings → API Credentials</a>.
                                    </p>
                                </div>

                                <template v-else>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium">Provider</label>
                                        <Listbox v-model="cleanForm.llm_provider">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full cursor-pointer rounded-lg border border-border bg-background px-4 py-2.5 text-left transition-colors hover:border-primary/50">
                                                    <span class="block truncate">{{
                                                        providerOptions.find((p) => p.id === cleanForm.llm_provider)?.name || 'Select provider'
                                                        }}</span>
                                                    <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                                        <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                                    </span>
                                                </ListboxButton>
                                                <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg">
                                                    <ListboxOption v-for="provider in providerOptions" :key="provider.id" :value="provider.id" v-slot="{ active, selected }">
                                                        <li :class="[
                                                            'relative cursor-pointer select-none px-4 py-2.5',
                                                            active ? 'bg-muted' : '',
                                                        ]">
                                                            <span :class="['block truncate', selected ? 'font-medium' : '']">
                                                                {{ provider.name }}
                                                            </span>
                                                            <span v-if="selected" class="absolute inset-y-0 right-0 flex items-center pr-3 text-primary">
                                                                <CheckIcon class="h-4 w-4" />
                                                            </span>
                                                        </li>
                                                    </ListboxOption>
                                                </ListboxOptions>
                                            </div>
                                        </Listbox>
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-sm font-medium">Model</label>
                                        <Listbox v-model="cleanForm.llm_model" :disabled="loadingModels || !providerModels.length">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full cursor-pointer rounded-lg border border-border bg-background px-4 py-2.5 text-left transition-colors hover:border-primary/50 disabled:cursor-not-allowed disabled:opacity-50">
                                                    <span v-if="loadingModels" class="flex items-center gap-2">
                                                        <Loader class="h-4 w-4 animate-spin" />
                                                        Loading models...
                                                    </span>
                                                    <span v-else-if="!providerModels.length" class="text-muted-foreground">
                                                        No models available
                                                    </span>
                                                    <span v-else class="block truncate">{{
                                                        providerModels.find((m) => m.id === cleanForm.llm_model)?.name || cleanForm.llm_model || 'Select model'
                                                        }}</span>
                                                    <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                                        <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                                    </span>
                                                </ListboxButton>
                                                <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg">
                                                    <ListboxOption v-for="model in providerModels" :key="model.id" :value="model.id" v-slot="{ active, selected }">
                                                        <li :class="[
                                                            'relative cursor-pointer select-none px-4 py-2.5',
                                                            active ? 'bg-muted' : '',
                                                        ]">
                                                            <span :class="['block truncate', selected ? 'font-medium' : '']">
                                                                {{ model.name }}
                                                            </span>
                                                            <span v-if="selected" class="absolute inset-y-0 right-0 flex items-center pr-3 text-primary">
                                                                <CheckIcon class="h-4 w-4" />
                                                            </span>
                                                        </li>
                                                    </ListboxOption>
                                                </ListboxOptions>
                                            </div>
                                        </Listbox>
                                    </div>
                                </template>
                            </div>

                            <div class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-900/20">
                                <p class="text-sm text-amber-700 dark:text-amber-300">
                                    ⚠️ Re-cleaning will overwrite current text and remove validation.
                                </p>
                                <button type="submit" :disabled="cleanForm.processing || (cleanForm.mode === 'llm' && (!providerOptions.length || !providerModels.length))" class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-amber-700 hover:shadow disabled:opacity-50">
                                    <ArrowPathIcon v-if="cleanForm.processing" class="h-4 w-4 animate-spin" />
                                    <SparklesIcon v-else class="h-4 w-4" />
                                    {{ cleanForm.processing ? 'Cleaning...' : 'Re-clean' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Text Content -->
                <div v-if="baseTranscription.text_clean || baseTranscription.text_raw" class="space-y-4">
                    <!-- View Toggle -->
                    <div class="flex flex-wrap gap-2">
                        <button v-if="baseTranscription.text_clean" @click="activeView = 'cleaned'" :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', activeView === 'cleaned' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                            Cleaned
                        </button>
                        <button @click="activeView = 'original'" :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', activeView === 'original' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                            Original
                        </button>
                        <button v-if="baseTranscription.text_clean && baseTranscription.text_raw" @click="activeView = 'diff'" :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', activeView === 'diff' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                            Diff
                        </button>
                    </div>

                    <!-- Cleaned Text -->
                    <div v-if="activeView === 'cleaned' && baseTranscription.text_clean" class="rounded-xl border bg-card p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-semibold">Cleaned Text</h3>
                            <div class="flex gap-2">
                                <button v-if="!isEditing" @click="startEditing" class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm hover:bg-muted">
                                    <PencilIcon class="h-4 w-4" />
                                    Edit
                                </button>
                            </div>
                        </div>
                        <div v-if="isEditing">
                            <textarea v-model="editedText" rows="12" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" dir="auto"></textarea>
                            <div class="flex gap-2 mt-4">
                                <button @click="saveEdit" :disabled="updateForm.processing" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                                    {{ updateForm.processing ? 'Saving...' : 'Save' }}
                                </button>
                                <button @click="cancelEditing" class="rounded-lg border px-4 py-2 text-sm hover:bg-muted">
                                    Cancel
                                </button>
                            </div>
                        </div>
                        <p v-else class="whitespace-pre-wrap text-sm" dir="auto">{{ decodedCleanText }}</p>
                    </div>

                    <!-- Original Text -->
                    <div v-if="activeView === 'original'" class="rounded-xl border bg-card p-6">
                        <h3 class="font-semibold mb-4">Original Text</h3>
                        <p class="whitespace-pre-wrap text-sm" dir="auto">{{ decodedRawText }}</p>
                    </div>

                    <!-- Diff View -->
                    <div v-if="activeView === 'diff' && charDiff.length" class="overflow-hidden rounded-xl border border-border bg-card">
                        <!-- Diff Header -->
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-muted/50 px-6 py-3">
                            <div class="flex items-center gap-4">
                                <h3 class="font-semibold">Changes</h3>
                                <div class="flex items-center gap-3 text-sm">
                                    <span class="flex items-center gap-1.5 text-red-600 dark:text-red-400">
                                        <span class="inline-block h-2.5 w-2.5 rounded-full bg-red-500"></span>
                                        {{ diffStats.deletions }} removed
                                    </span>
                                    <span class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                        <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                                        {{ diffStats.additions }} added
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <!-- View Mode Toggle -->
                                <div class="flex rounded-lg border border-border bg-background p-0.5">
                                    <button @click="diffViewMode = 'unified'" :class="[
                                        'rounded-md px-3 py-1 text-xs font-medium transition-all',
                                        diffViewMode === 'unified'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    ]">
                                        Unified
                                    </button>
                                    <button @click="diffViewMode = 'split'" :class="[
                                        'rounded-md px-3 py-1 text-xs font-medium transition-all',
                                        diffViewMode === 'split'
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    ]">
                                        Split
                                    </button>
                                </div>
                                <button v-if="!isDiffEditing" @click="startDiffEditing" class="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-medium transition-all hover:border-primary hover:bg-primary/10 hover:text-primary">
                                    <PencilIcon class="h-4 w-4" />
                                    Edit
                                </button>
                                <div v-else class="flex items-center gap-2">
                                    <button @click="cancelDiffEditing" class="rounded-lg border border-border px-3 py-1.5 text-sm font-medium transition-all hover:bg-muted">
                                        Cancel
                                    </button>
                                    <button @click="saveDiffEdit" :disabled="updateForm.processing" class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground transition-all hover:bg-primary/90 disabled:opacity-50">
                                        {{ updateForm.processing ? 'Saving...' : 'Save' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Unified Diff View (Inline) - uses liveDiff when editing -->
                        <div v-if="diffViewMode === 'unified'" class="max-h-80 overflow-auto p-6">
                            <div class="whitespace-pre-wrap font-sans text-base leading-loose" dir="auto">
                                <template v-for="(segment, i) in (isDiffEditing ? liveDiff : charDiff)" :key="'unified-' + i">
                                    <span v-if="segment.type === 'removed'" class="rounded bg-red-200 px-0.5 line-through decoration-red-500/70 text-red-900 dark:bg-red-900/50 dark:text-red-200">{{ segment.text }}</span><span v-else-if="segment.type === 'added'" class="rounded bg-emerald-200 px-0.5 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-200">{{ segment.text }}</span><span v-else>{{ segment.text }}</span>
                                </template>
                            </div>
                        </div>

                        <!-- Split Diff View (Side-by-side) -->
                        <div v-else class="grid lg:grid-cols-2">
                            <!-- Original Side -->
                            <div class="border-b border-border lg:border-b-0 lg:border-r">
                                <div class="flex items-center gap-2 border-b border-border bg-red-50/50 px-4 py-2 dark:bg-red-950/20">
                                    <span class="inline-block h-2 w-2 rounded-full bg-red-500"></span>
                                    <span class="text-sm font-medium text-red-700 dark:text-red-400">Original</span>
                                </div>
                                <div class="h-80 overflow-auto p-4">
                                    <div class="whitespace-pre-wrap font-sans text-base leading-loose" dir="auto">
                                        <template v-for="(segment, i) in liveDiff" :key="'orig-' + i">
                                            <span v-if="segment.type === 'removed'" class="rounded bg-red-200 px-0.5 line-through decoration-red-500/70 text-red-900 dark:bg-red-900/50 dark:text-red-200">{{ segment.text }}</span><span v-else-if="segment.type === 'added'" class="rounded bg-emerald-200 px-0.5 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-200">{{ segment.text }}</span><span v-else>{{ segment.text }}</span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <!-- Cleaned Side (editable when editing) -->
                            <div class="flex flex-col">
                                <div class="flex items-center gap-2 border-b border-border bg-emerald-50/50 px-4 py-2 dark:bg-emerald-950/20">
                                    <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                                    <span class="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                                        Cleaned{{ isDiffEditing ? ' (Editing)' : '' }}
                                    </span>
                                </div>
                                <!-- Editable textarea when editing in split mode -->
                                <textarea v-if="isDiffEditing" v-model="diffEditedText" class="h-80 flex-1 resize-none border-0 bg-emerald-50/30 p-4 font-sans text-base leading-loose focus:ring-0 dark:bg-emerald-950/10" dir="auto" placeholder="Edit the cleaned text here..."></textarea>
                                <!-- Read-only diff display when not editing -->
                                <div v-else class="h-80 overflow-auto p-4">
                                    <div class="whitespace-pre-wrap font-sans text-base leading-loose" dir="auto">
                                        <template v-for="(segment, i) in charDiff" :key="'clean-' + i">
                                            <span v-if="segment.type === 'added'" class="rounded bg-emerald-200 px-0.5 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-200">{{ segment.text }}</span>
                                            <span v-else-if="segment.type === 'same'">{{ segment.text }}</span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Editor (shown when editing in unified mode only) -->
                        <div v-if="isDiffEditing && diffViewMode === 'unified'" class="border-t border-border p-4">
                            <div class="flex items-center gap-2 mb-2 text-sm font-medium text-muted-foreground">
                                <PencilIcon class="h-4 w-4" />
                                Edit cleaned text below — diff updates live as you type
                            </div>
                            <textarea v-model="diffEditedText" rows="8" class="w-full resize-y rounded-lg border border-border bg-background p-4 font-sans text-base leading-loose focus:border-primary focus:ring-2 focus:ring-primary/20" dir="auto" placeholder="Edit the cleaned text here..."></textarea>
                        </div>

                        <!-- Diff Legend -->
                        <div class="border-t border-border bg-muted/30 px-6 py-3">
                            <div class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                                <span class="flex items-center gap-1.5">
                                    <span class="inline-block rounded bg-red-200 px-1.5 py-0.5 line-through decoration-red-500/70 text-red-900 dark:bg-red-900/50 dark:text-red-200">removed</span>
                                    Text that was in the original
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <span class="inline-block rounded bg-emerald-200 px-1.5 py-0.5 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-200">added</span>
                                    Text that was added during cleaning
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Validation Section -->
                <div v-if="baseTranscription.text_clean && !baseTranscription.validated_at" class="rounded-xl border-2 border-emerald-200 bg-emerald-50 p-6 dark:border-emerald-800 dark:bg-emerald-900/20">
                    <h2 class="mb-4 flex items-center gap-2 text-lg font-semibold">
                        <CheckCircleIcon class="h-5 w-5 text-emerald-600" />
                        Validate Transcription
                    </h2>
                    <p class="text-sm text-muted-foreground mb-4">
                        Mark this transcription as validated and ready for ASR benchmarking.
                    </p>
                    <form @submit.prevent="submitValidate" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Notes (optional)</label>
                            <textarea v-model="validateForm.notes" rows="2" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" placeholder="Any notes about this validation..."></textarea>
                        </div>
                        <button type="submit" :disabled="validateForm.processing" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-6 py-2 font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                            <CheckCircleIcon class="h-4 w-4" />
                            {{ validateForm.processing ? 'Validating...' : 'Validate' }}
                        </button>
                    </form>
                </div>

                <!-- Already Validated -->
                <div v-if="baseTranscription.validated_at" class="rounded-xl border bg-emerald-50 dark:bg-emerald-900/20 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold flex items-center gap-2">
                                <CheckCircleIcon class="h-5 w-5 text-emerald-600" />
                                Validated
                            </h3>
                            <p class="text-sm text-muted-foreground">
                                Validated {{ formatDate(baseTranscription.validated_at) }}
                                <span v-if="baseTranscription.validated_by"> by {{ baseTranscription.validated_by }}</span>
                            </p>
                            <p v-if="baseTranscription.review_notes" class="text-sm mt-2">{{ baseTranscription.review_notes }}</p>
                        </div>
                        <button @click="submitUnvalidate" class="rounded-lg border px-3 py-1.5 text-sm hover:bg-muted">
                            Remove Validation
                        </button>
                    </div>
                </div>
            </template>

            <!-- ==================== ASR TRANSCRIPTION VIEW ==================== -->
            <template v-else-if="isAsr && asrTranscription">
                <!-- Header -->
                <div class="flex flex-col gap-4 rounded-xl border bg-card p-6">
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
                            <Link v-if="audioSample" :href="`/audio-samples/${audioSample.id}`" class="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted">
                            Back to sample
                            </Link>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">Source</div>
                            <div class="font-medium">{{ asrTranscription.source === 'generated' ? 'API' : 'Manual' }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">
                                <span class="inline-flex items-center gap-1">
                                    WER
                                    <InformationCircleIcon class="h-4 w-4" v-tippy="'Word Error Rate'" />
                                </span>
                            </div>
                            <div class="font-mono font-semibold">{{ formatErrorRate(asrTranscription.wer) }}</div>
                            <div v-if="hasCustomRange" class="text-xs text-muted-foreground">
                                (custom range)
                            </div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-muted-foreground">
                                <span class="inline-flex items-center gap-1">
                                    CER
                                    <InformationCircleIcon class="h-4 w-4" v-tippy="'Character Error Rate'" />
                                </span>
                            </div>
                            <div class="font-mono text-muted-foreground">{{ formatErrorRate(asrTranscription.cer) }}</div>
                        </div>
                    </div>

                    <div v-if="asrTranscription.notes" class="text-sm text-muted-foreground">
                        {{ asrTranscription.notes }}
                    </div>
                </div>

                <!-- Missing reference text warning -->
                <div v-if="!referenceText" class="rounded-xl border border-dashed bg-card p-6 text-center text-sm text-muted-foreground">
                    No validated base transcription available for comparison.
                </div>

                <!-- Missing hypothesis warning -->
                <div v-else-if="!hypothesisText" class="rounded-xl border border-dashed bg-card p-6 text-center text-sm text-muted-foreground">
                    This transcription does not have hypothesis text yet.
                </div>

                <!-- ASR Comparison View -->
                <div v-else class="space-y-6">
                    <!-- Metrics Cards -->
                    <div class="grid gap-4 md:grid-cols-4">
                        <div class="rounded-xl border bg-card p-4 text-center">
                            <div class="text-3xl font-bold text-emerald-600">{{ asrTranscription.insertions ?? 0 }}</div>
                            <div class="text-xs text-muted-foreground">Insertions</div>
                        </div>
                        <div class="rounded-xl border bg-card p-4 text-center">
                            <div class="text-3xl font-bold text-rose-600">{{ asrTranscription.deletions ?? 0 }}</div>
                            <div class="text-xs text-muted-foreground">Deletions</div>
                        </div>
                        <div class="rounded-xl border bg-card p-4 text-center">
                            <div class="text-3xl font-bold text-amber-500">{{ asrTranscription.substitutions ?? 0 }}</div>
                            <div class="text-xs text-muted-foreground">Substitutions</div>
                        </div>
                        <div class="rounded-xl border bg-card p-4 text-center">
                            <div class="text-3xl font-bold text-red-600">{{ asrTranscription.wer?.toFixed(1) ?? 'N/A' }}%</div>
                            <div class="text-xs text-muted-foreground">WER</div>
                        </div>
                    </div>

                    <!-- View Toggle & Range Controls -->
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex flex-wrap gap-2">
                            <button @click="viewMode = 'alignment'" :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', viewMode === 'alignment' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                                Alignment View
                            </button>
                            <button @click="viewMode = 'side-by-side'" :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', viewMode === 'side-by-side' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                                Side-by-Side
                            </button>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <div v-if="hasCustomRange" class="text-xs text-muted-foreground">
                                Ref: {{ formatRange(asrTranscription.wer_ref_start, asrTranscription.wer_ref_end, totalRefWords) }} |
                                Hyp: {{ formatRange(asrTranscription.wer_hyp_start, asrTranscription.wer_hyp_end, totalHypWords) }}
                            </div>
                            <button 
                                @click="openRangeModal"
                                :class="['inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted', hasCustomRange ? 'border-primary text-primary' : '']"
                            >
                                <AdjustmentsHorizontalIcon class="h-4 w-4" />
                                {{ hasCustomRange ? 'Edit Range' : 'Set Range' }}
                            </button>
                        </div>
                    </div>

                    <!-- Alignment View -->
                    <div v-if="viewMode === 'alignment'" class="rounded-xl border bg-card p-6">
                        <div class="mb-4 font-semibold">Alignment Visualization</div>
                        <div class="space-y-4">
                            <div v-for="(chunk, index) in chunkedAlignment" :key="index" class="border-b pb-4 last:border-b-0" dir="rtl">
                                <div class="mb-2 flex flex-wrap gap-1">
                                    <span class="text-xs text-muted-foreground">Ref:</span>
                                    <span v-for="(item, idx) in chunk" :key="`ref-${index}-${idx}`" :class="[
                                        'rounded px-1.5 py-0.5 text-sm',
                                        item.type === 'correct' ? 'bg-muted' :
                                            item.type === 'sub' ? 'bg-amber-200' :
                                                item.type === 'del' ? 'bg-rose-200 line-through' : 'text-muted-foreground',
                                    ]">{{ item.type === 'ins' ? '—' : item.ref }}</span>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <span class="text-xs text-muted-foreground">Hyp:</span>
                                    <span v-for="(item, idx) in chunk" :key="`hyp-${index}-${idx}`" :class="[
                                        'rounded px-1.5 py-0.5 text-sm',
                                        item.type === 'correct' ? 'bg-muted' :
                                            item.type === 'sub' ? 'bg-amber-200' :
                                                item.type === 'ins' ? 'bg-emerald-200' : 'text-muted-foreground',
                                    ]">{{ item.type === 'del' ? '—' : item.hyp }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-4 text-sm">
                            <div class="flex items-center gap-2"><span class="h-3 w-3 rounded bg-muted"></span> Correct</div>
                            <div class="flex items-center gap-2"><span class="h-3 w-3 rounded bg-amber-200"></span> Substitution</div>
                            <div class="flex items-center gap-2"><span class="h-3 w-3 rounded bg-emerald-200"></span> Insertion</div>
                            <div class="flex items-center gap-2"><span class="h-3 w-3 rounded bg-rose-200"></span> Deletion</div>
                        </div>
                    </div>

                    <!-- Side-by-Side View -->
                    <div v-else class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-xl border bg-card p-4" dir="rtl">
                            <div class="mb-2 text-sm font-semibold text-muted-foreground">Reference (Cleaned)</div>
                            <p class="whitespace-pre-wrap text-sm">{{ referenceText }}</p>
                        </div>
                        <div class="rounded-xl border bg-card p-4" dir="rtl">
                            <div class="mb-2 text-sm font-semibold text-muted-foreground">Hypothesis</div>
                            <p class="whitespace-pre-wrap text-sm">{{ hypothesisText }}</p>
                        </div>
                    </div>

                    <!-- Word-Level Review Section -->
                    <div class="rounded-xl border border-border bg-card">
                        <!-- Audio Player for Word Playback -->
                        <div v-if="audioMedia" class="border-b border-border p-4">
                            <AudioPlayer
                                ref="audioPlayerRef"
                                :src="audioMedia.url"
                                :name="audioMedia.name"
                                :file-size="audioMedia.size"
                            />
                        </div>

                        <!-- Word Review Header with Training Flag -->
                        <div class="flex items-center justify-between border-b border-border px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary/15">
                                    <DocumentTextIcon class="h-5 w-5 text-secondary" />
                                </div>
                                <div>
                                    <h2 class="font-semibold text-foreground">Word-Level Review</h2>
                                    <p class="text-xs text-muted-foreground">
                                        Click words to play audio, edit corrections
                                        <span v-if="wordReviewStats">
                                            • {{ wordReviewStats.correction_count }} corrections ({{ (wordReviewStats.correction_rate * 100).toFixed(1) }}%)
                                        </span>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-3">
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

                                <!-- Collapse Toggle -->
                                <button
                                    @click="showWordReview = !showWordReview"
                                    class="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted"
                                >
                                    {{ showWordReview ? 'Hide' : 'Show' }}
                                </button>
                            </div>
                        </div>

                        <!-- Word Review Component -->
                        <div v-show="showWordReview" class="p-6">
                            <TranscriptionWordReview
                                :transcription-id="props.transcription.id"
                                :audio-player-ref="audioPlayerRef"
                                @stats-updated="handleWordReviewStats"
                                @alignment-started="handleAlignmentStarted"
                            />
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Link Audio Sample Modal -->
        <LinkAudioSampleModal v-if="isBase && baseTranscription" :is-open="showLinkModal" :transcription-id="baseTranscription.id" :transcription-name="baseTranscription.name" @close="showLinkModal = false" @linked="handleLinked" />

        <!-- WER Range Selection Modal -->
        <TransitionRoot appear :show="showRangeModal" as="template">
            <Dialog as="div" @close="showRangeModal = false; selectionMode = null" class="relative z-50">
                <TransitionChild
                    as="template"
                    enter="duration-300 ease-out"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="duration-200 ease-in"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-black/25 dark:bg-black/50" />
                </TransitionChild>

                <div class="fixed inset-0 overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center p-4">
                        <TransitionChild
                            as="template"
                            enter="duration-300 ease-out"
                            enter-from="opacity-0 scale-95"
                            enter-to="opacity-100 scale-100"
                            leave="duration-200 ease-in"
                            leave-from="opacity-100 scale-100"
                            leave-to="opacity-0 scale-95"
                        >
                            <DialogPanel class="w-full max-w-4xl transform rounded-2xl bg-card p-6 shadow-xl transition-all max-h-[90vh] flex flex-col">
                                <DialogTitle class="text-lg font-semibold flex-shrink-0">WER Calculation Range</DialogTitle>
                                <p class="mt-1 text-sm text-muted-foreground flex-shrink-0">
                                    Click on words to set start/end points, or enter indices manually. Selected range is highlighted.
                                </p>

                                <!-- Legend -->
                                <div class="mt-3 flex flex-wrap gap-4 text-xs flex-shrink-0">
                                    <div class="flex items-center gap-1.5">
                                        <span class="h-3 w-3 rounded ring-2 ring-green-500"></span>
                                        <span>Start</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="h-3 w-3 rounded ring-2 ring-red-500"></span>
                                        <span>End</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="h-3 w-3 rounded bg-primary/20"></span>
                                        <span>In Range</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="h-3 w-3 rounded bg-muted/50 opacity-50"></span>
                                        <span>Excluded</span>
                                    </div>
                                </div>

                                <form @submit.prevent="submitRangeRecalculate" class="mt-4 flex-1 overflow-hidden flex flex-col gap-4">
                                    <!-- Scrollable content area -->
                                    <div class="flex-1 overflow-y-auto space-y-4 pr-2">
                                        <!-- Reference Text Section -->
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between sticky top-0 bg-card py-1 z-10">
                                                <div class="text-sm font-medium">Reference Text</div>
                                                <div class="text-xs text-muted-foreground">
                                                    {{ totalRefWords }} words | Range: {{ rangeForm.ref_start ?? 0 }}-{{ rangeForm.ref_end ?? (totalRefWords - 1) }}
                                                </div>
                                            </div>
                                            
                                            <!-- Selection buttons -->
                                            <div class="flex flex-wrap gap-2">
                                                <button
                                                    type="button"
                                                    @click="selectionMode = selectionMode === 'ref-start' ? null : 'ref-start'"
                                                    :class="['rounded px-2 py-1 text-xs font-medium transition-colors', selectionMode === 'ref-start' ? 'bg-green-500 text-white' : 'bg-muted hover:bg-muted/80']"
                                                >
                                                    {{ selectionMode === 'ref-start' ? 'Click a word...' : 'Set Start' }}
                                                </button>
                                                <button
                                                    type="button"
                                                    @click="selectionMode = selectionMode === 'ref-end' ? null : 'ref-end'"
                                                    :class="['rounded px-2 py-1 text-xs font-medium transition-colors', selectionMode === 'ref-end' ? 'bg-red-500 text-white' : 'bg-muted hover:bg-muted/80']"
                                                >
                                                    {{ selectionMode === 'ref-end' ? 'Click a word...' : 'Set End' }}
                                                </button>
                                                <div class="flex-1"></div>
                                                <div class="flex items-center gap-1">
                                                    <input
                                                        v-model.number="rangeForm.ref_start"
                                                        type="number"
                                                        min="0"
                                                        :max="totalRefWords - 1"
                                                        placeholder="Start"
                                                        class="w-16 rounded border bg-background px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                                                    />
                                                    <span class="text-xs text-muted-foreground">to</span>
                                                    <input
                                                        v-model.number="rangeForm.ref_end"
                                                        type="number"
                                                        :min="rangeForm.ref_start ?? 0"
                                                        :max="totalRefWords - 1"
                                                        placeholder="End"
                                                        class="w-16 rounded border bg-background px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                                                    />
                                                </div>
                                            </div>
                                            
                                            <!-- Interactive word display -->
                                            <div class="max-h-48 overflow-y-auto rounded-lg border bg-muted/30 p-3" dir="rtl">
                                                <div class="flex flex-wrap gap-1">
                                                    <span
                                                        v-for="(word, idx) in refWords"
                                                        :key="`ref-word-${idx}`"
                                                        :class="getRefWordClass(idx)"
                                                        @click="handleRefWordClick(idx)"
                                                        :title="`Word ${idx}`"
                                                    >{{ word }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Hypothesis Text Section -->
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between sticky top-0 bg-card py-1 z-10">
                                                <div class="text-sm font-medium">Hypothesis Text</div>
                                                <div class="text-xs text-muted-foreground">
                                                    {{ totalHypWords }} words | Range: {{ rangeForm.hyp_start ?? 0 }}-{{ rangeForm.hyp_end ?? (totalHypWords - 1) }}
                                                </div>
                                            </div>
                                            
                                            <!-- Selection buttons -->
                                            <div class="flex flex-wrap gap-2">
                                                <button
                                                    type="button"
                                                    @click="selectionMode = selectionMode === 'hyp-start' ? null : 'hyp-start'"
                                                    :class="['rounded px-2 py-1 text-xs font-medium transition-colors', selectionMode === 'hyp-start' ? 'bg-green-500 text-white' : 'bg-muted hover:bg-muted/80']"
                                                >
                                                    {{ selectionMode === 'hyp-start' ? 'Click a word...' : 'Set Start' }}
                                                </button>
                                                <button
                                                    type="button"
                                                    @click="selectionMode = selectionMode === 'hyp-end' ? null : 'hyp-end'"
                                                    :class="['rounded px-2 py-1 text-xs font-medium transition-colors', selectionMode === 'hyp-end' ? 'bg-red-500 text-white' : 'bg-muted hover:bg-muted/80']"
                                                >
                                                    {{ selectionMode === 'hyp-end' ? 'Click a word...' : 'Set End' }}
                                                </button>
                                                <div class="flex-1"></div>
                                                <div class="flex items-center gap-1">
                                                    <input
                                                        v-model.number="rangeForm.hyp_start"
                                                        type="number"
                                                        min="0"
                                                        :max="totalHypWords - 1"
                                                        placeholder="Start"
                                                        class="w-16 rounded border bg-background px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                                                    />
                                                    <span class="text-xs text-muted-foreground">to</span>
                                                    <input
                                                        v-model.number="rangeForm.hyp_end"
                                                        type="number"
                                                        :min="rangeForm.hyp_start ?? 0"
                                                        :max="totalHypWords - 1"
                                                        placeholder="End"
                                                        class="w-16 rounded border bg-background px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                                                    />
                                                </div>
                                            </div>
                                            
                                            <!-- Interactive word display -->
                                            <div class="max-h-48 overflow-y-auto rounded-lg border bg-muted/30 p-3" dir="rtl">
                                                <div class="flex flex-wrap gap-1">
                                                    <span
                                                        v-for="(word, idx) in hypWords"
                                                        :key="`hyp-word-${idx}`"
                                                        :class="getHypWordClass(idx)"
                                                        @click="handleHypWordClick(idx)"
                                                        :title="`Word ${idx}`"
                                                    >{{ word }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Actions - Fixed at bottom -->
                                    <div class="flex items-center justify-between pt-3 border-t flex-shrink-0">
                                        <button
                                            type="button"
                                            @click="resetRange"
                                            class="text-sm text-muted-foreground hover:text-foreground"
                                        >
                                            Reset to Full Text
                                        </button>
                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                @click="showRangeModal = false; selectionMode = null"
                                                class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                type="submit"
                                                :disabled="rangeForm.processing"
                                                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                            >
                                                <ArrowPathIcon v-if="rangeForm.processing" class="h-4 w-4 animate-spin" />
                                                Recalculate WER
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>
    </AppLayout>
</template>
