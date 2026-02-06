<script setup lang="ts">
import {
    Listbox,
    ListboxButton,
    ListboxOption,
    ListboxOptions,
} from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import {
    ArrowPathIcon,
    CheckCircleIcon,
    CpuChipIcon,
    LinkIcon,
    PencilIcon,
    SparklesIcon,
    TrashIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline';
import { Loader } from 'lucide-vue-next';

import type { AudioMedia, Preset } from '@/types/audio-samples';
import type { LlmModel, LlmProvider } from '@/types/transcription-show';
import type { BaseTranscription } from '@/types/transcriptions';

interface Props {
    transcription: BaseTranscription;
    audioSample?: {
        id: number;
        name: string;
        base_transcription?: {
            text_clean: string | null;
        } | null;
    } | null;
    audioMedia?: AudioMedia | null;
    presets?: Record<string, Preset>;
    llmProviders?: Record<string, LlmProvider>;
}

const props = defineProps<Props>();

// ==================== VIEW STATE ====================
const activeView = ref<'cleaned' | 'original' | 'side-by-side' | 'diff'>('cleaned');
const diffViewMode = ref<'split' | 'unified'>('unified');
const isEditing = ref(false);
const editedText = ref('');
const showCleanForm = ref(false);

// ==================== CLEANING FORM ====================
const cleanForm = useForm({
    preset: props.transcription.cleaning_preset ?? 'titles_only',
    mode: 'rule' as 'rule' | 'llm',
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
});

// LLM model state (providers come from deferred prop, models fetched on demand)
const loadingModels = ref(false);
const providerModels = ref<LlmModel[]>([]);

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
    Object.entries(props.llmProviders ?? {}).filter(([, value]) => value.has_credential)
);

const providerOptions = computed(() =>
    availableLlmProviders.value.map(([key, value]) => ({
        id: key,
        name: value.name,
        hasCredential: value.has_credential,
    }))
);

// When llmProviders deferred prop arrives, initialize provider selection
watch(
    () => props.llmProviders,
    (providers) => {
        if (!providers) return;
        const firstAvailable = availableLlmProviders.value[0]?.[0] ?? null;
        if (firstAvailable && !providers[cleanForm.llm_provider]?.has_credential) {
            cleanForm.llm_provider = firstAvailable;
        }
    },
    { immediate: true }
);

// Fetch models for selected provider
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

// Watch provider changes — lazy-load models only when needed
watch(
    () => cleanForm.llm_provider,
    (newProvider) => {
        if (newProvider && cleanForm.mode === 'llm') {
            fetchModelsForProvider(newProvider);
        }
    }
);

// Fetch models when switching to LLM mode (if not already loaded)
watch(
    () => cleanForm.mode,
    (mode) => {
        if (mode === 'llm' && cleanForm.llm_provider && providerModels.value.length === 0) {
            fetchModelsForProvider(cleanForm.llm_provider);
        }
    }
);

const submitClean = () => {
    cleanForm.post(route('transcriptions.clean', { transcription: props.transcription.id }), {
        preserveScroll: true,
        only: ['transcription'],
        onSuccess: () => {
            showCleanForm.value = false;
        },
    });
};

// ==================== EDIT FORM ====================
const updateForm = useForm({
    text_clean: '',
});

// Name editing
const isEditingName = ref(false);
const nameForm = useForm({
    name: props.transcription.name || '',
});

const startEditingName = () => {
    nameForm.name = props.transcription.name || '';
    isEditingName.value = true;
};

const cancelEditingName = () => {
    isEditingName.value = false;
    nameForm.name = props.transcription.name || '';
};

const saveName = () => {
    nameForm.patch(route('transcriptions.update', { transcription: props.transcription.id }), {
        preserveScroll: true,
        only: ['transcription'],
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
    updateForm.patch(route('transcriptions.update', { transcription: props.transcription.id }), {
        preserveScroll: true,
        only: ['transcription'],
        onSuccess: () => {
            isEditing.value = false;
            editedText.value = '';
        },
    });
};

// ==================== VALIDATION ====================
const validateForm = useForm({
    notes: '',
});

const submitValidate = () => {
    validateForm.post(route('transcriptions.validate', { transcription: props.transcription.id }), {
        preserveScroll: true,
        only: ['transcription'],
    });
};

const submitUnvalidate = () => {
    router.delete(route('transcriptions.unvalidate', { transcription: props.transcription.id }), {
        preserveScroll: true,
        only: ['transcription'],
    });
};

// ==================== LINK / DELETE ====================
const showLinkModal = ref(false);

const handleLinked = () => {
    showLinkModal.value = false;
};

const submitUnlink = () => {
    router.delete(route('transcriptions.unlink', { transcription: props.transcription.id }), {
        preserveScroll: true,
        only: ['transcription', 'audioSample'],
    });
};

const deleteTranscription = () => {
    if (!confirm('Delete this transcription? This cannot be undone.')) return;
    router.delete(route('transcriptions.destroy', { transcription: props.transcription.id }));
};

// ==================== TEXT DECODE / DIFF ====================
const decodedRawText = computed(() =>
    props.transcription.text_raw
        ? decodeHtmlEntities(props.transcription.text_raw)
        : ''
);

const decodedCleanText = computed(() =>
    props.transcription.text_clean
        ? decodeHtmlEntities(props.transcription.text_clean)
        : ''
);

// Editable diff state
const isDiffEditing = ref(false);
const diffEditedText = ref('');
const debouncedEditedText = ref('');
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

watch(diffEditedText, (newValue) => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(() => {
        debouncedEditedText.value = newValue;
    }, 300);
});

const liveDiff = computed(() => {
    if (!props.transcription.text_raw) return [];
    const compareText = isDiffEditing.value
        ? debouncedEditedText.value
        : decodedCleanText.value;
    if (!compareText) return [];
    return generateDiff(decodedRawText.value, compareText);
});

const charDiff = computed(() => {
    if (!props.transcription.text_raw || !props.transcription.text_clean) return [];
    return generateDiff(decodedRawText.value, decodedCleanText.value);
});

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
    updateForm.patch(route('transcriptions.update', { transcription: props.transcription.id }), {
        preserveScroll: true,
        only: ['transcription'],
        onSuccess: () => {
            isDiffEditing.value = false;
            diffEditedText.value = '';
        },
    });
};
</script>

<template>
    <div class="contents">
        <!-- Header -->
        <div class="flex flex-col gap-4 rounded-xl border bg-card p-4 sm:p-6">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <!-- Editable name -->
                    <div v-if="isEditingName" class="flex flex-wrap items-center gap-2">
                        <input
                            v-model="nameForm.name"
                            type="text"
                            class="w-full rounded-lg border border-border bg-background px-3 py-1.5 text-lg font-bold focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 sm:w-auto sm:text-xl"
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
                    <h1 v-else class="group flex items-center gap-2 text-xl font-bold sm:text-2xl">
                        {{ transcription.name || `Transcription #${transcription.id}` }}
                        <button
                            @click="startEditingName"
                            class="rounded p-2 text-muted-foreground opacity-100 transition-opacity hover:bg-muted hover:text-foreground sm:opacity-0 sm:group-hover:opacity-100"
                            title="Edit name"
                        >
                            <PencilIcon class="h-4 w-4" />
                        </button>
                    </h1>
                    <p class="text-sm text-muted-foreground">Base Transcription</p>
                </div>
                <div class="flex items-center gap-2">
                    <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', statusClass(transcription.status)]">
                        {{ formatStatus(transcription.status) }}
                    </span>
                    <span v-if="transcription.validated_at" class="flex items-center gap-1 text-emerald-600 text-sm">
                        <CheckCircleIcon class="h-4 w-4" />
                        Validated
                    </span>
                </div>
            </div>

            <!-- Info Grid -->
            <div class="grid gap-3 grid-cols-2 sm:gap-4 sm:grid-cols-3 md:grid-cols-5">
                <div>
                    <div class="text-xs uppercase text-muted-foreground">Source</div>
                    <div class="font-medium capitalize">{{ transcription.source }}</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-muted-foreground">Clean Rate</div>
                    <div class="font-mono font-semibold">
                        {{ transcription.clean_rate !== null ? `${transcription.clean_rate}%` : '—' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs uppercase text-muted-foreground">Linked Audio</div>
                    <div v-if="transcription.audio_sample">
                        <Link :href="route('audio-samples.show', { audioSample: transcription.audio_sample.id })" class="flex items-center gap-1 text-primary hover:underline">
                        <LinkIcon class="h-4 w-4" />
                        {{ transcription.audio_sample.name }}
                        </Link>
                    </div>
                    <div v-else class="text-muted-foreground">Not linked</div>
                </div>
                <div>
                    <div class="text-xs uppercase text-muted-foreground">Created By</div>
                    <div class="text-sm">
                        {{ formatCreatedBy(transcription?.user, undefined) }}
                    </div>
                </div>
                <div>
                    <div class="text-xs uppercase text-muted-foreground">Created</div>
                    <div class="text-sm">{{ formatDate(transcription.created_at) }}</div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap gap-2 pt-2 border-t">
                <button v-if="!transcription.audio_sample_id" @click="showLinkModal = true" class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm hover:bg-muted">
                    <LinkIcon class="h-4 w-4" />
                    Link to Audio Sample
                </button>
                <button v-if="transcription.audio_sample_id" @click="submitUnlink" class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm hover:bg-muted">
                    <XCircleIcon class="h-4 w-4" />
                    Unlink
                </button>
                <button @click="deleteTranscription" class="flex items-center gap-1 rounded-lg border border-destructive text-destructive px-3 py-1.5 text-sm hover:bg-destructive/10">
                    <TrashIcon class="h-4 w-4" />
                    Delete
                </button>
            </div>
        </div>

        <!-- Audio Player -->
        <div v-if="audioMedia" class="rounded-xl border bg-card p-4">
            <AudioPlayer
                :src="audioMedia.url"
                :name="audioMedia.name"
                :file-size="audioMedia.size"
            />
        </div>

        <!-- Cleaning Section (if needs cleaning) -->
        <div v-if="transcription.text_raw && !transcription.text_clean && transcription.status !== 'processing'" class="rounded-xl border-2 border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20 sm:p-6">
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
                            <Link :href="route('settings.credentials')" class="text-primary hover:underline">Settings</Link>.
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
                            <Link :href="route('settings.credentials')" class="text-primary hover:underline">Settings</Link>.
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
        <div v-if="transcription.status === 'processing'" class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="bg-blue-500/10 px-6 py-8 text-center">
                <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-500/20">
                    <ArrowPathIcon class="h-7 w-7 animate-spin text-blue-600" />
                </div>
                <p class="text-lg font-semibold text-foreground">Cleaning in progress...</p>
                <p class="mt-1 text-sm text-muted-foreground">This page will refresh when complete.</p>
            </div>
        </div>

        <!-- Re-clean Section (if already cleaned) -->
        <div v-if="transcription.text_clean && transcription.status !== 'processing'" class="overflow-hidden rounded-xl border border-border bg-card transition-colors hover:border-amber-500/50">
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
                    <button type="button" @click="showCleanForm = !showCleanForm" class="rounded-lg border px-4 py-2 text-sm font-medium transition-colors" :class="showCleanForm
                        ? 'border-warning bg-warning/10 text-warning'
                        : 'border-border text-muted-foreground hover:border-warning hover:bg-warning/10 hover:text-warning'">
                        {{ showCleanForm ? 'Hide Options' : 'Show Options' }}
                    </button>
                </div>
            </div>

            <div class="p-4 sm:p-6">
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
                                'flex-1 rounded-lg border-2 px-4 py-3 text-sm font-medium transition-colors',
                                cleanForm.mode === 'rule'
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border hover:border-primary/50 hover:bg-muted',
                            ]">
                                <span class="block">Rule-based</span>
                                <span class="mt-0.5 block text-xs font-normal opacity-70">Fast & predictable</span>
                            </button>
                            <button type="button" @click="cleanForm.mode = 'llm'" :class="[
                                'flex-1 rounded-lg border-2 px-4 py-3 text-sm font-medium transition-colors',
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
                                <Link :href="route('settings.credentials')" class="font-medium underline hover:no-underline">Settings → API Credentials</Link>.
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
                            Re-cleaning will overwrite current text and remove validation.
                        </p>
                        <button type="submit" :disabled="cleanForm.processing || (cleanForm.mode === 'llm' && (!providerOptions.length || !providerModels.length))" class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-amber-700 hover:shadow disabled:opacity-50">
                            <ArrowPathIcon v-if="cleanForm.processing" class="h-4 w-4 animate-spin" />
                            <SparklesIcon v-else class="h-4 w-4" />
                            {{ cleanForm.processing ? 'Cleaning...' : 'Re-clean' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Text Content -->
        <div v-if="transcription.text_clean || transcription.text_raw" class="space-y-4">
            <!-- View Toggle -->
            <div class="flex flex-wrap gap-2">
                <button v-if="transcription.text_clean" @click="activeView = 'cleaned'" :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', activeView === 'cleaned' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                    Cleaned
                </button>
                <button @click="activeView = 'original'" :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', activeView === 'original' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                    Original
                </button>
                <button v-if="transcription.text_clean && transcription.text_raw" @click="activeView = 'diff'" :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', activeView === 'diff' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                    Diff
                </button>
            </div>

            <!-- Cleaned Text -->
            <div v-if="activeView === 'cleaned' && transcription.text_clean" class="rounded-xl border bg-card p-4 sm:p-6">
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
            <div v-if="activeView === 'original'" class="rounded-xl border bg-card p-4 sm:p-6">
                <h3 class="font-semibold mb-4">Original Text</h3>
                <p class="whitespace-pre-wrap text-sm" dir="auto">{{ decodedRawText }}</p>
            </div>

            <!-- Diff View -->
            <div v-if="activeView === 'diff' && charDiff.length" class="overflow-hidden rounded-xl border border-border bg-card">
                <!-- Diff Header -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-muted/50 px-4 py-3 sm:px-6">
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
                                'rounded-md px-3 py-1 text-xs font-medium transition-colors',
                                diffViewMode === 'unified'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]">
                                Unified
                            </button>
                            <button @click="diffViewMode = 'split'" :class="[
                                'rounded-md px-3 py-1 text-xs font-medium transition-colors',
                                diffViewMode === 'split'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]">
                                Split
                            </button>
                        </div>
                        <button v-if="!isDiffEditing" @click="startDiffEditing" class="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-medium transition-colors hover:border-primary hover:bg-primary/10 hover:text-primary">
                            <PencilIcon class="h-4 w-4" />
                            Edit
                        </button>
                        <div v-else class="flex items-center gap-2">
                            <button @click="cancelDiffEditing" class="rounded-lg border border-border px-3 py-1.5 text-sm font-medium transition-colors hover:bg-muted">
                                Cancel
                            </button>
                            <button @click="saveDiffEdit" :disabled="updateForm.processing" class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-50">
                                {{ updateForm.processing ? 'Saving...' : 'Save' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Unified Diff View -->
                <div v-if="diffViewMode === 'unified'" class="max-h-60 overflow-auto p-4 sm:max-h-80 sm:p-6">
                    <div class="whitespace-pre-wrap font-sans text-base leading-loose" dir="auto">
                        <template v-for="(segment, i) in (isDiffEditing ? liveDiff : charDiff)" :key="'unified-' + i">
                            <span v-if="segment.type === 'removed'" class="rounded bg-red-200 px-0.5 line-through decoration-red-500/70 text-red-900 dark:bg-red-900/50 dark:text-red-200">{{ segment.text }}</span><span v-else-if="segment.type === 'added'" class="rounded bg-emerald-200 px-0.5 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-200">{{ segment.text }}</span><span v-else>{{ segment.text }}</span>
                        </template>
                    </div>
                </div>

                <!-- Split Diff View -->
                <div v-else class="grid lg:grid-cols-2">
                    <!-- Original Side -->
                    <div class="border-b border-border lg:border-b-0 lg:border-r">
                        <div class="flex items-center gap-2 border-b border-border bg-red-50/50 px-4 py-2 dark:bg-red-950/20">
                            <span class="inline-block h-2 w-2 rounded-full bg-red-500"></span>
                            <span class="text-sm font-medium text-red-700 dark:text-red-400">Original</span>
                        </div>
                        <div class="h-60 overflow-auto p-4 sm:h-80">
                            <div class="whitespace-pre-wrap font-sans text-base leading-loose" dir="auto">
                                <template v-for="(segment, i) in liveDiff" :key="'orig-' + i">
                                    <span v-if="segment.type === 'removed'" class="rounded bg-red-200 px-0.5 line-through decoration-red-500/70 text-red-900 dark:bg-red-900/50 dark:text-red-200">{{ segment.text }}</span><span v-else-if="segment.type === 'added'" class="rounded bg-emerald-200 px-0.5 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-200">{{ segment.text }}</span><span v-else>{{ segment.text }}</span>
                                </template>
                            </div>
                        </div>
                    </div>
                    <!-- Cleaned Side -->
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2 border-b border-border bg-emerald-50/50 px-4 py-2 dark:bg-emerald-950/20">
                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                            <span class="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                                Cleaned{{ isDiffEditing ? ' (Editing)' : '' }}
                            </span>
                        </div>
                        <textarea v-if="isDiffEditing" v-model="diffEditedText" class="h-60 flex-1 resize-none border-0 bg-emerald-50/30 p-4 font-sans text-base leading-loose focus:ring-0 dark:bg-emerald-950/10 sm:h-80" dir="auto" placeholder="Edit the cleaned text here..."></textarea>
                        <div v-else class="h-60 overflow-auto p-4 sm:h-80">
                            <div class="whitespace-pre-wrap font-sans text-base leading-loose" dir="auto">
                                <template v-for="(segment, i) in charDiff" :key="'clean-' + i">
                                    <span v-if="segment.type === 'added'" class="rounded bg-emerald-200 px-0.5 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-200">{{ segment.text }}</span>
                                    <span v-else-if="segment.type === 'same'">{{ segment.text }}</span>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Editor (unified mode) -->
                <div v-if="isDiffEditing && diffViewMode === 'unified'" class="border-t border-border p-4">
                    <div class="flex items-center gap-2 mb-2 text-sm font-medium text-muted-foreground">
                        <PencilIcon class="h-4 w-4" />
                        Edit cleaned text below — diff updates live as you type
                    </div>
                    <textarea v-model="diffEditedText" rows="8" class="w-full resize-y rounded-lg border border-border bg-background p-4 font-sans text-base leading-loose focus:border-primary focus:ring-2 focus:ring-primary/20" dir="auto" placeholder="Edit the cleaned text here..."></textarea>
                </div>

                <!-- Diff Legend -->
                <div class="border-t border-border bg-muted/30 px-6 py-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground sm:gap-4">
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
        <div v-if="transcription.text_clean && !transcription.validated_at" class="rounded-xl border-2 border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-900/20 sm:p-6">
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
        <div v-if="transcription.validated_at" class="rounded-xl border bg-emerald-50 p-4 dark:bg-emerald-900/20 sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-semibold flex items-center gap-2">
                        <CheckCircleIcon class="h-5 w-5 text-emerald-600" />
                        Validated
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Validated {{ formatDate(transcription.validated_at) }}
                        <span v-if="transcription.validated_by"> by {{ transcription.validated_by }}</span>
                    </p>
                    <p v-if="transcription.review_notes" class="text-sm mt-2">{{ transcription.review_notes }}</p>
                </div>
                <button @click="submitUnvalidate" class="rounded-lg border px-3 py-1.5 text-sm hover:bg-muted">
                    Remove Validation
                </button>
            </div>
        </div>

        <!-- Link Audio Sample Modal -->
        <LinkAudioSampleModal :is-open="showLinkModal" :transcription-id="transcription.id" :transcription-name="transcription.name" @close="showLinkModal = false" @linked="handleLinked" />
    </div>
</template>
