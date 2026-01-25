<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import LinkAudioSampleModal from '@/components/transcriptions/LinkAudioSampleModal.vue';
import { type BreadcrumbItem } from '@/types';
import type { Preset } from '@/types/audio-samples';
import type { BaseTranscription, AsrTranscription, Transcription } from '@/types/transcriptions';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { decodeHtmlEntities } from '@/lib/utils';
import { formatErrorRate } from '@/lib/asrMetrics';
import { formatDate } from '@/lib/date';
import * as Diff from 'diff';
import { computed, ref, watch, onMounted } from 'vue';
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
} from '@heroicons/vue/24/outline';
import {
    Listbox,
    ListboxButton,
    ListboxOption,
    ListboxOptions,
} from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import { Loader } from 'lucide-vue-next';

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

const props = defineProps<{
    transcription: Transcription;
    audioSample?: {
        id: number;
        name: string;
        base_transcription?: {
            text_clean: string | null;
        } | null;
    } | null;
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

// Diff for base transcription
type DiffSegment = { type: 'same' | 'removed' | 'added'; text: string };

const charDiff = computed(() => {
    if (!baseTranscription.value?.text_raw || !baseTranscription.value?.text_clean) {
        return [];
    }
    const parts = Diff.diffWords(
        decodedRawText.value,
        decodedCleanText.value
    );
    const segments: DiffSegment[] = [];
    for (const part of parts) {
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

// ==================== ASR TRANSCRIPTION STATE ====================

const viewMode = ref<'alignment' | 'side-by-side'>('alignment');

// Get reference text for ASR comparison
const referenceText = computed(() => {
    if (isAsr.value && props.audioSample?.base_transcription?.text_clean) {
        return decodeHtmlEntities(props.audioSample.base_transcription.text_clean);
    }
    return '';
});

const hypothesisText = computed(() => asrTranscription.value?.hypothesis_text ?? '');

type AlignmentItem = {
    type: 'correct' | 'sub' | 'ins' | 'del';
    ref: string | null;
    hyp: string | null;
};

const tokenize = (value: string) =>
    (value.match(/[^\s]+/g) || []).filter(Boolean);

const buildAlignmentFromDiff = (refText: string, hypText: string) => {
    const refTokens = tokenize(refText);
    const hypTokens = tokenize(hypText);
    const parts = Diff.diffArrays(refTokens, hypTokens);
    const alignment: AlignmentItem[] = [];

    let i = 0;
    while (i < parts.length) {
        const part = parts[i];
        const next = parts[i + 1];

        if (part.removed && next?.added) {
            const removedWords = part.value as string[];
            const addedWords = next.value as string[];
            const pairCount = Math.min(removedWords.length, addedWords.length);

            for (let idx = 0; idx < pairCount; idx++) {
                alignment.push({ type: 'sub', ref: removedWords[idx], hyp: addedWords[idx] });
            }
            for (let idx = pairCount; idx < removedWords.length; idx++) {
                alignment.push({ type: 'del', ref: removedWords[idx], hyp: null });
            }
            for (let idx = pairCount; idx < addedWords.length; idx++) {
                alignment.push({ type: 'ins', ref: null, hyp: addedWords[idx] });
            }
            i += 2;
            continue;
        }

        if (part.added) {
            (part.value as string[]).forEach((word) => {
                alignment.push({ type: 'ins', ref: null, hyp: word });
            });
        } else if (part.removed) {
            (part.value as string[]).forEach((word) => {
                alignment.push({ type: 'del', ref: word, hyp: null });
            });
        } else {
            (part.value as string[]).forEach((word) => {
                alignment.push({ type: 'correct', ref: word, hyp: word });
            });
        }
        i += 1;
    }
    return alignment;
};

const alignment = computed(() => {
    if (!referenceText.value.trim() || !hypothesisText.value.trim()) return [];
    return buildAlignmentFromDiff(referenceText.value, hypothesisText.value);
});

const asrMetrics = computed(() => {
    if (!alignment.value.length) {
        return { ins: 0, del: 0, sub: 0, wer: 0 };
    }
    let ins = 0, del = 0, sub = 0;
    for (const item of alignment.value) {
        if (item.type === 'ins') ins++;
        if (item.type === 'del') del++;
        if (item.type === 'sub') sub++;
    }
    const refCount = tokenize(referenceText.value).length || 1;
    const wer = ((ins + del + sub) / refCount) * 100;
    return { ins, del, sub, wer };
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

const formatStatus = (status: string) => {
    const map: Record<string, string> = {
        pending: 'Pending',
        processing: 'Processing',
        completed: 'Completed',
        failed: 'Failed',
    };
    return map[status] ?? status;
};

const statusClass = (status: string) => {
    const map: Record<string, string> = {
        pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    };
    return map[status] ?? 'bg-muted text-muted-foreground';
};
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
                            <h1 class="text-2xl font-bold">{{ baseTranscription.name || `Transcription #${baseTranscription.id}` }}</h1>
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
                    <div class="grid gap-4 md:grid-cols-4">
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
                            <div class="text-xs uppercase text-muted-foreground">Created</div>
                            <div class="text-sm">{{ formatDate(baseTranscription.created_at) }}</div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-wrap gap-2 pt-2 border-t">
                        <button
                            v-if="!baseTranscription.audio_sample_id"
                            @click="showLinkModal = true"
                            class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm hover:bg-muted"
                        >
                            <LinkIcon class="h-4 w-4" />
                            Link to Audio Sample
                        </button>
                        <button
                            v-if="baseTranscription.audio_sample_id"
                            @click="submitUnlink"
                            class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm hover:bg-muted"
                        >
                            <XCircleIcon class="h-4 w-4" />
                            Unlink
                        </button>
                        <button
                            @click="deleteTranscription"
                            class="flex items-center gap-1 rounded-lg border border-destructive text-destructive px-3 py-1.5 text-sm hover:bg-destructive/10"
                        >
                            <TrashIcon class="h-4 w-4" />
                            Delete
                        </button>
                    </div>
                </div>

                <!-- Cleaning Section (if needs cleaning) -->
                <div
                    v-if="baseTranscription.text_raw && !baseTranscription.text_clean && baseTranscription.status !== 'processing'"
                    class="rounded-xl border-2 border-blue-200 bg-blue-50 p-6 dark:border-blue-800 dark:bg-blue-900/20"
                >
                    <h2 class="mb-4 flex items-center gap-2 text-lg font-semibold">
                        <SparklesIcon class="h-5 w-5 text-blue-600" />
                        Clean This Transcript
                    </h2>
                    <form @submit.prevent="submitClean" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Cleaning Mode</label>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    @click="cleanForm.mode = 'rule'"
                                    :class="[
                                        'flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 font-medium transition-colors',
                                        cleanForm.mode === 'rule' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
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
                                        cleanForm.mode === 'llm' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                                    ]"
                                >
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
                                        <span>{{ presetOptions.find(p => p.id === cleanForm.preset)?.name || cleanForm.preset }}</span>
                                        <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                            <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                        </span>
                                    </ListboxButton>
                                    <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                        <ListboxOption
                                            v-for="preset in presetOptions"
                                            :key="preset.id"
                                            :value="preset.id"
                                            v-slot="{ active, selected }"
                                        >
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
                                            <span>{{ providerOptions.find(p => p.id === cleanForm.llm_provider)?.name || cleanForm.llm_provider }}</span>
                                            <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                            </span>
                                        </ListboxButton>
                                        <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                            <ListboxOption
                                                v-for="provider in providerOptions"
                                                :key="provider.id"
                                                :value="provider.id"
                                                v-slot="{ active, selected }"
                                            >
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
                                            <span>{{ providerModels.find(m => m.id === cleanForm.llm_model)?.name || cleanForm.llm_model }}</span>
                                            <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                            </span>
                                        </ListboxButton>
                                        <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                            <ListboxOption
                                                v-for="model in providerModels"
                                                :key="model.id"
                                                :value="model.id"
                                                v-slot="{ active, selected }"
                                            >
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

                        <button
                            type="submit"
                            :disabled="cleanForm.processing || (cleanForm.mode === 'llm' && (!providerOptions.length || !providerModels.length))"
                            class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            <ArrowPathIcon v-if="cleanForm.processing" class="h-4 w-4 animate-spin" />
                            <SparklesIcon v-else class="h-4 w-4" />
                            {{ cleanForm.processing ? 'Cleaning...' : 'Start Cleaning' }}
                        </button>
                    </form>
                </div>

                <!-- Processing indicator -->
                <div v-if="baseTranscription.status === 'processing'" class="rounded-xl border bg-blue-50 dark:bg-blue-900/20 p-6 text-center">
                    <ArrowPathIcon class="h-8 w-8 animate-spin mx-auto text-blue-600 mb-2" />
                    <p class="font-medium">Cleaning in progress...</p>
                    <p class="text-sm text-muted-foreground">This page will refresh when complete.</p>
                </div>

                <!-- Text Content -->
                <div v-if="baseTranscription.text_clean || baseTranscription.text_raw" class="space-y-4">
                    <!-- View Toggle -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="baseTranscription.text_clean"
                            @click="activeView = 'cleaned'"
                            :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', activeView === 'cleaned' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']"
                        >
                            Cleaned
                        </button>
                        <button
                            @click="activeView = 'original'"
                            :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', activeView === 'original' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']"
                        >
                            Original
                        </button>
                        <button
                            v-if="baseTranscription.text_clean && baseTranscription.text_raw"
                            @click="activeView = 'diff'"
                            :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', activeView === 'diff' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']"
                        >
                            Diff
                        </button>
                    </div>

                    <!-- Cleaned Text -->
                    <div v-if="activeView === 'cleaned' && baseTranscription.text_clean" class="rounded-xl border bg-card p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-semibold">Cleaned Text</h3>
                            <div class="flex gap-2">
                                <button
                                    v-if="!isEditing"
                                    @click="startEditing"
                                    class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm hover:bg-muted"
                                >
                                    <PencilIcon class="h-4 w-4" />
                                    Edit
                                </button>
                            </div>
                        </div>
                        <div v-if="isEditing">
                            <textarea
                                v-model="editedText"
                                rows="12"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                                dir="auto"
                            ></textarea>
                            <div class="flex gap-2 mt-4">
                                <button
                                    @click="saveEdit"
                                    :disabled="updateForm.processing"
                                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                >
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
                    <div v-if="activeView === 'diff' && charDiff.length" class="rounded-xl border bg-card p-6">
                        <h3 class="font-semibold mb-4">Changes (Diff)</h3>
                        <div class="whitespace-pre-wrap text-sm" dir="auto">
                            <span
                                v-for="(segment, i) in charDiff"
                                :key="i"
                                :class="{
                                    'bg-red-200 line-through': segment.type === 'removed',
                                    'bg-green-200': segment.type === 'added',
                                }"
                            >{{ segment.text }}</span>
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
                            <textarea
                                v-model="validateForm.notes"
                                rows="2"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                                placeholder="Any notes about this validation..."
                            ></textarea>
                        </div>
                        <button
                            type="submit"
                            :disabled="validateForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-6 py-2 font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
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
                        <button
                            @click="submitUnvalidate"
                            class="rounded-lg border px-3 py-1.5 text-sm hover:bg-muted"
                        >
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
                            <Link
                                v-if="audioSample"
                                :href="`/audio-samples/${audioSample.id}`"
                                class="rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted"
                            >
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
                            <div class="text-3xl font-bold text-emerald-600">{{ asrMetrics.ins }}</div>
                            <div class="text-xs text-muted-foreground">Insertions</div>
                        </div>
                        <div class="rounded-xl border bg-card p-4 text-center">
                            <div class="text-3xl font-bold text-rose-600">{{ asrMetrics.del }}</div>
                            <div class="text-xs text-muted-foreground">Deletions</div>
                        </div>
                        <div class="rounded-xl border bg-card p-4 text-center">
                            <div class="text-3xl font-bold text-amber-500">{{ asrMetrics.sub }}</div>
                            <div class="text-xs text-muted-foreground">Substitutions</div>
                        </div>
                        <div class="rounded-xl border bg-card p-4 text-center">
                            <div class="text-3xl font-bold text-red-600">{{ asrMetrics.wer.toFixed(1) }}%</div>
                            <div class="text-xs text-muted-foreground">WER</div>
                        </div>
                    </div>

                    <!-- View Toggle -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            @click="viewMode = 'alignment'"
                            :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', viewMode === 'alignment' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']"
                        >
                            Alignment View
                        </button>
                        <button
                            @click="viewMode = 'side-by-side'"
                            :class="['rounded-lg border px-3 py-1.5 text-sm font-medium', viewMode === 'side-by-side' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']"
                        >
                            Side-by-Side
                        </button>
                    </div>

                    <!-- Alignment View -->
                    <div v-if="viewMode === 'alignment'" class="rounded-xl border bg-card p-6">
                        <div class="mb-4 font-semibold">Alignment Visualization</div>
                        <div class="space-y-4">
                            <div v-for="(chunk, index) in chunkedAlignment" :key="index" class="border-b pb-4 last:border-b-0" dir="rtl">
                                <div class="mb-2 flex flex-wrap gap-1">
                                    <span class="text-xs text-muted-foreground">Ref:</span>
                                    <span
                                        v-for="(item, idx) in chunk"
                                        :key="`ref-${index}-${idx}`"
                                        :class="[
                                            'rounded px-1.5 py-0.5 text-sm',
                                            item.type === 'correct' ? 'bg-muted' :
                                            item.type === 'sub' ? 'bg-amber-200' :
                                            item.type === 'del' ? 'bg-rose-200 line-through' : 'text-muted-foreground',
                                        ]"
                                    >{{ item.type === 'ins' ? '—' : item.ref }}</span>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <span class="text-xs text-muted-foreground">Hyp:</span>
                                    <span
                                        v-for="(item, idx) in chunk"
                                        :key="`hyp-${index}-${idx}`"
                                        :class="[
                                            'rounded px-1.5 py-0.5 text-sm',
                                            item.type === 'correct' ? 'bg-muted' :
                                            item.type === 'sub' ? 'bg-amber-200' :
                                            item.type === 'ins' ? 'bg-emerald-200' : 'text-muted-foreground',
                                        ]"
                                    >{{ item.type === 'del' ? '—' : item.hyp }}</span>
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
                </div>
            </template>
        </div>

        <!-- Link Audio Sample Modal -->
        <LinkAudioSampleModal
            v-if="isBase && baseTranscription"
            :is-open="showLinkModal"
            :transcription-id="baseTranscription.id"
            :transcription-name="baseTranscription.name"
            @close="showLinkModal = false"
            @linked="handleLinked"
        />
    </AppLayout>
</template>
