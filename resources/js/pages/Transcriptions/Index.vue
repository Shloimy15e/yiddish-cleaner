<script setup lang="ts">
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import {
    DocumentTextIcon,
    LinkIcon,
    CheckCircleIcon,
    PlusIcon,
    MagnifyingGlassIcon,
    SparklesIcon,
    CpuChipIcon,
    ArrowPathIcon,
} from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import type { ColumnDef } from '@/components/ui/data-table/types';
import { formatCreatedBy } from '@/lib/createdBy';
import { formatDate } from '@/lib/date';
import { type BreadcrumbItem } from '@/types';
import type { TranscriptionListItem } from '@/types/transcriptions';

interface CleaningPreset {
    name: string;
    description: string;
    processors: string[];
}

interface LlmProvider {
    name: string;
    has_credential: boolean;
    default_model: string;
    models: { id: string; name: string }[];
}

const props = defineProps<{
    transcriptions: {
        data: TranscriptionListItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search?: string;
        status?: string;
        linked?: string;
    };
    presets: Record<string, CleaningPreset>;
    llmProviders?: Record<string, LlmProvider>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Base Transcriptions', href: route('transcriptions.index') },
];

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const linkedFilter = ref(props.filters.linked || '');

// Selection via composable
const { selectedIds, selectedCount, clearSelection, selectedArray } = useTableSelection(
    computed(() => props.transcriptions.data),
);

// Column definitions
const columns: ColumnDef<TranscriptionListItem>[] = [
    { key: 'name', label: 'Name' },
    { key: 'status', label: 'Status' },
    { key: 'clean_rate', label: 'Clean Rate' },
    { key: 'linked', label: 'Linked' },
    { key: 'created_by', label: 'Created By' },
    { key: 'created_at', label: 'Created' },
];

// Bulk clean modal state
const showBulkCleanModal = ref(false);
const bulkCleanForm = useForm({
    ids: [] as number[],
    preset: 'titles_only',
    mode: 'rule' as 'rule' | 'llm',
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
    include_already_cleaned: false,
});

// LLM providers/models (loaded via optional prop)
const providerModels = ref<{ id: string; name: string }[]>([]);
const loadingModels = ref(false);
const loadingProviders = ref(false);

// Only show providers that user has authenticated
const authenticatedProviders = computed(() => {
    return Object.fromEntries(
        Object.entries(props.llmProviders ?? {}).filter(([, provider]) => provider.has_credential)
    );
});

const hasAuthenticatedProviders = computed(() => Object.keys(authenticatedProviders.value).length > 0);

const fetchModelsForProvider = async (provider: string) => {
    loadingModels.value = true;
    try {
        const response = await fetch(route('api.llm.models', { provider }));
        const data = await response.json();
        providerModels.value = data.models || data;
    } catch (error) {
        console.error('Failed to fetch models:', error);
        providerModels.value = (props.llmProviders ?? {})[provider]?.models || [];
    } finally {
        loadingModels.value = false;
    }
};

watch(
    () => bulkCleanForm.llm_provider,
    (newProvider) => {
        if (newProvider && showBulkCleanModal.value) {
            fetchModelsForProvider(newProvider);
        }
    }
);

// Initialize form defaults when providers load
watch(
    () => props.llmProviders,
    (providers) => {
        if (!providers) return;
        loadingProviders.value = false;
        const authKeys = Object.keys(authenticatedProviders.value);
        if (authKeys.length > 0 && !authenticatedProviders.value[bulkCleanForm.llm_provider]) {
            bulkCleanForm.llm_provider = authKeys[0];
        }
        if (bulkCleanForm.llm_provider && authenticatedProviders.value[bulkCleanForm.llm_provider]) {
            fetchModelsForProvider(bulkCleanForm.llm_provider);
        }
    },
);

const openBulkCleanModal = () => {
    showBulkCleanModal.value = true;
    loadingProviders.value = true;
    router.reload({ only: ['llmProviders'] });
};

const submitBulkClean = () => {
    bulkCleanForm.ids = selectedArray.value as number[];
    bulkCleanForm.post(route('transcriptions.bulk-clean'), {
        preserveScroll: true,
        onSuccess: () => {
            showBulkCleanModal.value = false;
            clearSelection();
            bulkCleanForm.reset();
        },
    });
};

// Count selected items that are already cleaned
const selectedAlreadyCleanedCount = computed(() => {
    return props.transcriptions.data.filter(
        (t) => selectedIds.value.has(t.id) && t.status === 'completed' && t.clean_rate !== null
    ).length;
});

const statusOptions = [
    { value: '', label: 'All Status' },
    { value: 'pending', label: 'Pending' },
    { value: 'processing', label: 'Processing' },
    { value: 'completed', label: 'Completed' },
    { value: 'failed', label: 'Failed' },
];

const linkedOptions = [
    { value: '', label: 'All' },
    { value: 'linked', label: 'Linked to Audio' },
    { value: 'orphan', label: 'Not Linked' },
];

const selectedStatus = computed(
    () => statusOptions.find((o) => o.value === statusFilter.value) || statusOptions[0],
);
const selectedLinked = computed(
    () => linkedOptions.find((o) => o.value === linkedFilter.value) || linkedOptions[0],
);

// Apply filters with debounce
let searchTimeout: ReturnType<typeof setTimeout>;
watch([search, statusFilter, linkedFilter], () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            route('transcriptions.index'),
            {
                search: search.value || undefined,
                status: statusFilter.value || undefined,
                linked: linkedFilter.value || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    }, 300);
});

const goToPage = (page: number) => {
    router.get(
        route('transcriptions.index'),
        { ...props.filters, page },
        { preserveState: true },
    );
};

// Status badge styling
const getStatusClass = (status: string) => {
    switch (status) {
        case 'pending':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
        case 'processing':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
        case 'completed':
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400';
        case 'failed':
            return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
        default:
            return 'bg-muted text-muted-foreground';
    }
};

const getStatusLabel = (status: string) => {
    switch (status) {
        case 'pending': return 'Pending';
        case 'processing': return 'Processing';
        case 'completed': return 'Completed';
        case 'failed': return 'Failed';
        default: return status;
    }
};
</script>

<template>
    <Head title="Base Transcriptions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <!-- Header -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Base Transcriptions</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        Manage reference transcriptions for audio sample benchmarking
                    </p>
                </div>
                <Link
                    :href="route('transcriptions.create')"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    <PlusIcon class="h-4 w-4" />
                    New Transcription
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                <div class="relative flex-1">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search transcriptions..."
                        class="w-full rounded-lg border bg-background py-2 pl-10 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                    />
                </div>

                <SelectMenu v-model="statusFilter" :options="statusOptions" class="w-full sm:w-44" />
                <SelectMenu v-model="linkedFilter" :options="linkedOptions" class="w-full sm:w-44" />
            </div>

            <!-- Results count and bulk actions -->
            <div class="flex items-center justify-between">
                <div class="text-sm text-muted-foreground">
                    {{ transcriptions.total }} transcription{{ transcriptions.total !== 1 ? 's' : '' }}
                </div>
                <div v-if="selectedCount > 0" class="flex items-center gap-3">
                    <span class="text-sm text-muted-foreground">{{ selectedCount }} selected</span>
                    <button type="button" @click="openBulkCleanModal" class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                        <SparklesIcon class="h-4 w-4" />
                        Bulk Clean
                    </button>
                    <button type="button" @click="clearSelection" class="text-sm text-muted-foreground hover:text-foreground">
                        Clear selection
                    </button>
                </div>
            </div>

            <!-- Table -->
            <DataTable
                :columns="columns"
                :items="transcriptions.data"
                selectable
                v-model:selected="selectedIds"
                table-class="min-w-[800px]"
            >
                <template #cell-name="{ item }">
                    <Link :href="route('transcriptions.show', { transcription: item.id })" class="flex items-center gap-2 font-medium hover:text-primary">
                        <DocumentTextIcon class="h-4 w-4 text-muted-foreground" />
                        {{ item.name || `Transcription #${item.id}` }}
                    </Link>
                </template>

                <template #cell-status="{ item }">
                    <div class="flex items-center gap-2">
                        <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', getStatusClass(item.status)]">
                            {{ getStatusLabel(item.status) }}
                        </span>
                        <CheckCircleIcon v-if="item.validated_at" class="h-4 w-4 text-emerald-500" v-tippy="'Validated'" />
                    </div>
                </template>

                <template #cell-clean_rate="{ item }">
                    <span v-if="item.clean_rate !== null" class="font-mono text-sm">{{ item.clean_rate }}%</span>
                    <span v-else class="text-muted-foreground text-sm">—</span>
                </template>

                <template #cell-linked="{ item }">
                    <div v-if="item.audio_sample" class="flex items-center gap-1.5">
                        <LinkIcon class="h-4 w-4 text-primary" />
                        <Link :href="route('audio-samples.show', { audioSample: item.audio_sample.id })" class="text-sm hover:text-primary truncate max-w-[150px]" :title="item.audio_sample.name">
                            {{ item.audio_sample.name }}
                        </Link>
                    </div>
                    <span v-else class="text-muted-foreground text-sm">Not linked</span>
                </template>

                <template #cell-created_by="{ item }">
                    <span class="text-sm text-muted-foreground whitespace-nowrap">{{ formatCreatedBy(item.user, undefined) }}</span>
                </template>

                <template #cell-created_at="{ item }">
                    <span class="text-sm text-muted-foreground">{{ formatDate(item.created_at) }}</span>
                </template>

                <template #empty>
                    <DocumentTextIcon class="mx-auto h-12 w-12 opacity-50 mb-4" />
                    <p class="font-medium">No transcriptions found</p>
                    <p class="text-sm mt-1">Create a new transcription to get started</p>
                </template>
            </DataTable>

            <!-- Pagination -->
            <TablePagination
                :current-page="transcriptions.current_page"
                :last-page="transcriptions.last_page"
                :per-page="transcriptions.per_page"
                :total="transcriptions.total"
                noun="transcriptions"
                @page-change="goToPage"
            />
        </div>

        <!-- Bulk Clean Modal (unchanged) -->
        <Modal :show="showBulkCleanModal" max-width="md" @close="showBulkCleanModal = false">
            <template #title>Bulk Clean Transcriptions</template>
                                <form @submit.prevent="submitBulkClean" class="space-y-4 p-6">
                                    <div class="rounded-lg bg-muted/50 p-3 text-sm">
                                        <p><span class="font-medium">{{ selectedCount }}</span> transcription(s) selected</p>
                                        <p v-if="selectedAlreadyCleanedCount > 0" class="text-muted-foreground mt-1">{{ selectedAlreadyCleanedCount }} already cleaned</p>
                                    </div>
                                    <div v-if="selectedAlreadyCleanedCount > 0" class="flex items-start gap-3">
                                        <input id="include_already_cleaned" v-model="bulkCleanForm.include_already_cleaned" type="checkbox" class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" />
                                        <label for="include_already_cleaned" class="text-sm">
                                            <span class="font-medium">Re-clean already cleaned transcriptions</span>
                                            <p class="text-muted-foreground">This will overwrite existing cleaned text and reset validation</p>
                                        </label>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium">Cleaning Mode</label>
                                        <div class="flex gap-2">
                                            <button type="button" @click="bulkCleanForm.mode = 'rule'" :class="['flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors', bulkCleanForm.mode === 'rule' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                                                <CpuChipIcon class="h-4 w-4" /> Rule-based
                                            </button>
                                            <button type="button" @click="bulkCleanForm.mode = 'llm'" :class="['flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors', bulkCleanForm.mode === 'llm' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">
                                                <SparklesIcon class="h-4 w-4" /> AI (LLM)
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="bulkCleanForm.mode === 'rule'">
                                        <label class="mb-2 block text-sm font-medium">Cleaning Preset</label>
                                        <Listbox v-model="bulkCleanForm.preset">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                                    <span>{{ presets[bulkCleanForm.preset]?.name || bulkCleanForm.preset }}</span>
                                                    <span class="absolute inset-y-0 right-0 flex items-center pr-2"><ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" /></span>
                                                </ListboxButton>
                                                <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                                    <ListboxOption v-for="(preset, key) in presets" :key="key" :value="key" v-slot="{ active, selected }">
                                                        <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm', active ? 'bg-muted' : '']">
                                                            <span :class="['block', selected ? 'font-medium' : '']">{{ preset.name }}</span>
                                                            <span class="block text-xs text-muted-foreground">{{ preset.description }}</span>
                                                            <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"><CheckIcon class="h-4 w-4" /></span>
                                                        </li>
                                                    </ListboxOption>
                                                </ListboxOptions>
                                            </div>
                                        </Listbox>
                                    </div>
                                    <div v-if="bulkCleanForm.mode === 'llm'" class="space-y-3">
                                        <div v-if="!hasAuthenticatedProviders" class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-800 dark:bg-yellow-900/20">
                                            <p class="text-sm text-yellow-800 dark:text-yellow-200">No LLM providers configured. <Link href="/settings/credentials" class="font-medium underline hover:no-underline">Add API credentials</Link> to use AI cleaning.</p>
                                        </div>
                                        <template v-else>
                                            <div>
                                                <div class="mb-2 flex items-center justify-between">
                                                    <label class="block text-sm font-medium">Provider</label>
                                                    <Link href="/settings/credentials" class="text-xs text-muted-foreground hover:text-primary">Add more</Link>
                                                </div>
                                                <Listbox v-model="bulkCleanForm.llm_provider">
                                                    <div class="relative">
                                                        <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                                            <span class="block truncate">{{ authenticatedProviders[bulkCleanForm.llm_provider]?.name || bulkCleanForm.llm_provider }}</span>
                                                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"><ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" /></span>
                                                        </ListboxButton>
                                                        <ListboxOptions class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                                            <ListboxOption v-for="(provider, key) in authenticatedProviders" :key="key" :value="key" v-slot="{ active, selected }">
                                                                <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm whitespace-nowrap', active ? 'bg-muted' : '']">
                                                                    <span :class="['block', selected ? 'font-medium' : '']">{{ provider.name }}</span>
                                                                    <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"><CheckIcon class="h-4 w-4" /></span>
                                                                </li>
                                                            </ListboxOption>
                                                        </ListboxOptions>
                                                    </div>
                                                </Listbox>
                                            </div>
                                            <div>
                                                <label class="mb-2 block text-sm font-medium">Model</label>
                                                <Listbox v-model="bulkCleanForm.llm_model" :disabled="loadingModels">
                                                    <div class="relative">
                                                        <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50">
                                                            <span class="block truncate">{{ providerModels.find(m => m.id === bulkCleanForm.llm_model)?.name || bulkCleanForm.llm_model }}</span>
                                                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"><ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" /></span>
                                                        </ListboxButton>
                                                        <ListboxOptions class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                                            <ListboxOption v-for="model in providerModels" :key="model.id" :value="model.id" v-slot="{ active, selected }">
                                                                <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm whitespace-nowrap', active ? 'bg-muted' : '']">
                                                                    <span :class="['block', selected ? 'font-medium' : '']">{{ model.name }}</span>
                                                                    <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"><CheckIcon class="h-4 w-4" /></span>
                                                                </li>
                                                            </ListboxOption>
                                                        </ListboxOptions>
                                                    </div>
                                                </Listbox>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="flex justify-end gap-3 pt-2">
                                        <button type="button" @click="showBulkCleanModal = false" class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted">Cancel</button>
                                        <button type="submit" :disabled="bulkCleanForm.processing || (bulkCleanForm.mode === 'llm' && !hasAuthenticatedProviders)" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                                            <ArrowPathIcon v-if="bulkCleanForm.processing" class="h-4 w-4 animate-spin" />
                                            <SparklesIcon v-else class="h-4 w-4" />
                                            {{ bulkCleanForm.processing ? 'Starting...' : 'Start Cleaning' }}
                                        </button>
                                    </div>
                                </form>
        </Modal>
    </AppLayout>
</template>
