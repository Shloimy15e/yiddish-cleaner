<script setup lang="ts">
import { Listbox, ListboxButton, ListboxOption, ListboxOptions, Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
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
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { TranscriptionListItem } from '@/types/transcriptions';
import { formatDate } from '@/lib/date';

interface CleaningPreset {
    name: string;
    description: string;
    processors: string[];
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
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Base Transcriptions', href: '/transcriptions' },
];

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const linkedFilter = ref(props.filters.linked || '');

// Selection state
const selectedIds = ref<Set<number>>(new Set());
const selectAll = ref(false);

// Selection helpers
const isSelected = (id: number) => selectedIds.value.has(id);
const toggleSelection = (id: number) => {
    if (selectedIds.value.has(id)) {
        selectedIds.value.delete(id);
    } else {
        selectedIds.value.add(id);
    }
    selectedIds.value = new Set(selectedIds.value);
    updateSelectAll();
};

const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedIds.value = new Set();
    } else {
        selectedIds.value = new Set(props.transcriptions.data.map((t) => t.id));
    }
    selectAll.value = !selectAll.value;
};

const updateSelectAll = () => {
    selectAll.value =
        props.transcriptions.data.length > 0 &&
        props.transcriptions.data.every((t) => selectedIds.value.has(t.id));
};

const selectedCount = computed(() => selectedIds.value.size);

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

// LLM providers/models
interface LlmProvider {
    name: string;
    has_credential: boolean;
    default_model: string;
    models: { id: string; name: string }[];
}
const allLlmProviders = ref<Record<string, LlmProvider>>({});
const providerModels = ref<{ id: string; name: string }[]>([]);
const loadingModels = ref(false);

// Only show providers that user has authenticated
const authenticatedProviders = computed(() => {
    return Object.fromEntries(
        Object.entries(allLlmProviders.value).filter(([_, provider]) => provider.has_credential)
    );
});

const hasAuthenticatedProviders = computed(() => Object.keys(authenticatedProviders.value).length > 0);

const fetchProviders = async () => {
    try {
        const response = await fetch('/api/llm/providers');
        allLlmProviders.value = await response.json();
    } catch (error) {
        console.error('Failed to fetch LLM providers:', error);
    }
};

const fetchModelsForProvider = async (provider: string) => {
    loadingModels.value = true;
    try {
        const response = await fetch(`/api/llm/providers/${provider}/models`);
        const data = await response.json();
        providerModels.value = data.models || data;
    } catch (error) {
        console.error('Failed to fetch models:', error);
        providerModels.value = allLlmProviders.value[provider]?.models || [];
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

const openBulkCleanModal = async () => {
    showBulkCleanModal.value = true;
    await fetchProviders();
    // Set default provider to first authenticated one if current is not authenticated
    const authKeys = Object.keys(authenticatedProviders.value);
    if (authKeys.length > 0 && !authenticatedProviders.value[bulkCleanForm.llm_provider]) {
        bulkCleanForm.llm_provider = authKeys[0];
    }
    // Also fetch models for the initial/default provider
    if (bulkCleanForm.llm_provider && authenticatedProviders.value[bulkCleanForm.llm_provider]) {
        fetchModelsForProvider(bulkCleanForm.llm_provider);
    }
};

const submitBulkClean = () => {
    bulkCleanForm.ids = Array.from(selectedIds.value);
    bulkCleanForm.post('/transcriptions/bulk-clean', {
        preserveScroll: true,
        onSuccess: () => {
            showBulkCleanModal.value = false;
            selectedIds.value = new Set();
            selectAll.value = false;
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

// Count selected items that can be cleaned
const selectedCleanableCount = computed(() => {
    return props.transcriptions.data.filter(
        (t) => selectedIds.value.has(t.id) && t.status !== 'processing'
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
            '/transcriptions',
            {
                search: search.value || undefined,
                status: statusFilter.value || undefined,
                linked: linkedFilter.value || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    }, 300);
});

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
        case 'pending':
            return 'Pending';
        case 'processing':
            return 'Processing';
        case 'completed':
            return 'Completed';
        case 'failed':
            return 'Failed';
        default:
            return status;
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
                    href="/transcriptions/create"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    <PlusIcon class="h-4 w-4" />
                    New Transcription
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                <!-- Search -->
                <div class="relative flex-1">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search transcriptions..."
                        class="w-full rounded-lg border bg-background py-2 pl-10 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                    />
                </div>

                <!-- Status Filter -->
                <Listbox v-model="statusFilter">
                    <div class="relative w-full sm:w-44">
                        <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <span>{{ selectedStatus.label }}</span>
                            <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                <ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" />
                            </span>
                        </ListboxButton>
                        <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg focus:outline-none">
                            <ListboxOption
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                                v-slot="{ active, selected }"
                            >
                                <li :class="[
                                    'relative cursor-pointer select-none py-2 pl-10 pr-4 text-sm',
                                    active ? 'bg-muted' : '',
                                ]">
                                    <span :class="['block truncate', selected ? 'font-medium' : '']">
                                        {{ option.label }}
                                    </span>
                                    <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                        <CheckIcon class="h-4 w-4" />
                                    </span>
                                </li>
                            </ListboxOption>
                        </ListboxOptions>
                    </div>
                </Listbox>

                <!-- Linked Filter -->
                <Listbox v-model="linkedFilter">
                    <div class="relative w-full sm:w-44">
                        <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <span>{{ selectedLinked.label }}</span>
                            <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                <ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" />
                            </span>
                        </ListboxButton>
                        <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg focus:outline-none">
                            <ListboxOption
                                v-for="option in linkedOptions"
                                :key="option.value"
                                :value="option.value"
                                v-slot="{ active, selected }"
                            >
                                <li :class="[
                                    'relative cursor-pointer select-none py-2 pl-10 pr-4 text-sm',
                                    active ? 'bg-muted' : '',
                                ]">
                                    <span :class="['block truncate', selected ? 'font-medium' : '']">
                                        {{ option.label }}
                                    </span>
                                    <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                        <CheckIcon class="h-4 w-4" />
                                    </span>
                                </li>
                            </ListboxOption>
                        </ListboxOptions>
                    </div>
                </Listbox>
            </div>

            <!-- Results count and bulk actions -->
            <div class="flex items-center justify-between">
                <div class="text-sm text-muted-foreground">
                    {{ transcriptions.total }} transcription{{ transcriptions.total !== 1 ? 's' : '' }}
                </div>
                
                <!-- Bulk action bar -->
                <div v-if="selectedCount > 0" class="flex items-center gap-3">
                    <span class="text-sm text-muted-foreground">
                        {{ selectedCount }} selected
                    </span>
                    <button
                        type="button"
                        @click="openBulkCleanModal"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        <SparklesIcon class="h-4 w-4" />
                        Bulk Clean
                    </button>
                    <button
                        type="button"
                        @click="selectedIds = new Set(); selectAll = false"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Clear selection
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="rounded-xl border bg-card overflow-hidden">
                <table class="w-full">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="w-12 px-4 py-3">
                                <input
                                    type="checkbox"
                                    :checked="selectAll"
                                    :indeterminate="selectedCount > 0 && !selectAll"
                                    @change="toggleSelectAll"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                />
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                Name
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                Status
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                Clean Rate
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                Linked
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                Created
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="transcription in transcriptions.data"
                            :key="transcription.id"
                            :class="[
                                'hover:bg-muted/50 transition-colors',
                                isSelected(transcription.id) ? 'bg-primary/5' : '',
                            ]"
                        >
                            <td class="px-4 py-3">
                                <input
                                    type="checkbox"
                                    :checked="isSelected(transcription.id)"
                                    @change="toggleSelection(transcription.id)"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/transcriptions/${transcription.id}`"
                                    class="flex items-center gap-2 font-medium hover:text-primary"
                                >
                                    <DocumentTextIcon class="h-4 w-4 text-muted-foreground" />
                                    {{ transcription.name || `Transcription #${transcription.id}` }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span :class="[
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                        getStatusClass(transcription.status),
                                    ]">
                                        {{ getStatusLabel(transcription.status) }}
                                    </span>
                                    <CheckCircleIcon
                                        v-if="transcription.validated_at"
                                        class="h-4 w-4 text-emerald-500"
                                        v-tippy="'Validated'"
                                    />
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="transcription.clean_rate !== null" class="font-mono text-sm">
                                    {{ transcription.clean_rate }}%
                                </span>
                                <span v-else class="text-muted-foreground text-sm">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="transcription.audio_sample" class="flex items-center gap-1.5">
                                    <LinkIcon class="h-4 w-4 text-primary" />
                                    <Link
                                        :href="`/audio-samples/${transcription.audio_sample.id}`"
                                        class="text-sm hover:text-primary truncate max-w-[150px]"
                                        :title="transcription.audio_sample.name"
                                    >
                                        {{ transcription.audio_sample.name }}
                                    </Link>
                                </div>
                                <span v-else class="text-muted-foreground text-sm">Not linked</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">
                                {{ formatDate(transcription.created_at) }}
                            </td>
                        </tr>
                        <tr v-if="transcriptions.data.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">
                                <DocumentTextIcon class="mx-auto h-12 w-12 opacity-50 mb-4" />
                                <p class="font-medium">No transcriptions found</p>
                                <p class="text-sm mt-1">Create a new transcription to get started</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="transcriptions.last_page > 1" class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    Showing {{ (transcriptions.current_page - 1) * transcriptions.per_page + 1 }} to
                    {{ Math.min(transcriptions.current_page * transcriptions.per_page, transcriptions.total) }}
                    of {{ transcriptions.total }}
                </p>
                <div class="flex gap-2">
                    <Link
                        v-for="page in transcriptions.last_page"
                        :key="page"
                        :href="`/transcriptions?page=${page}${search ? `&search=${search}` : ''}${statusFilter ? `&status=${statusFilter}` : ''}${linkedFilter ? `&linked=${linkedFilter}` : ''}`"
                        :class="[
                            'rounded-lg px-3 py-1 text-sm',
                            page === transcriptions.current_page
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted hover:bg-muted/80',
                        ]"
                    >
                        {{ page }}
                    </Link>
                </div>
            </div>
        </div>

        <!-- Bulk Clean Modal -->
        <TransitionRoot appear :show="showBulkCleanModal" as="template">
            <Dialog as="div" @close="showBulkCleanModal = false" class="relative z-50">
                <TransitionChild
                    as="template"
                    enter="duration-300 ease-out"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="duration-200 ease-in"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-black/25 backdrop-blur-sm" />
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
                            <DialogPanel class="w-full max-w-md transform rounded-2xl bg-background border p-6 shadow-xl transition-all">
                                <div class="flex items-center justify-between mb-4">
                                    <DialogTitle as="h3" class="text-lg font-semibold">
                                        Bulk Clean Transcriptions
                                    </DialogTitle>
                                    <button
                                        type="button"
                                        @click="showBulkCleanModal = false"
                                        class="rounded-lg p-1 text-muted-foreground hover:bg-muted"
                                    >
                                        <XMarkIcon class="h-5 w-5" />
                                    </button>
                                </div>

                                <form @submit.prevent="submitBulkClean" class="space-y-4">
                                    <!-- Selection summary -->
                                    <div class="rounded-lg bg-muted/50 p-3 text-sm">
                                        <p>
                                            <span class="font-medium">{{ selectedCount }}</span> transcription(s) selected
                                        </p>
                                        <p v-if="selectedAlreadyCleanedCount > 0" class="text-muted-foreground mt-1">
                                            {{ selectedAlreadyCleanedCount }} already cleaned
                                        </p>
                                    </div>

                                    <!-- Re-clean option -->
                                    <div v-if="selectedAlreadyCleanedCount > 0" class="flex items-start gap-3">
                                        <input
                                            id="include_already_cleaned"
                                            v-model="bulkCleanForm.include_already_cleaned"
                                            type="checkbox"
                                            class="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                        />
                                        <label for="include_already_cleaned" class="text-sm">
                                            <span class="font-medium">Re-clean already cleaned transcriptions</span>
                                            <p class="text-muted-foreground">
                                                This will overwrite existing cleaned text and reset validation
                                            </p>
                                        </label>
                                    </div>

                                    <!-- Cleaning Mode -->
                                    <div>
                                        <label class="mb-2 block text-sm font-medium">Cleaning Mode</label>
                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                @click="bulkCleanForm.mode = 'rule'"
                                                :class="[
                                                    'flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors',
                                                    bulkCleanForm.mode === 'rule' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                                                ]"
                                            >
                                                <CpuChipIcon class="h-4 w-4" />
                                                Rule-based
                                            </button>
                                            <button
                                                type="button"
                                                @click="bulkCleanForm.mode = 'llm'"
                                                :class="[
                                                    'flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors',
                                                    bulkCleanForm.mode === 'llm' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                                                ]"
                                            >
                                                <SparklesIcon class="h-4 w-4" />
                                                AI (LLM)
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Preset Selection (Rule-based) -->
                                    <div v-if="bulkCleanForm.mode === 'rule'">
                                        <label class="mb-2 block text-sm font-medium">Cleaning Preset</label>
                                        <Listbox v-model="bulkCleanForm.preset">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                                    <span>{{ presets[bulkCleanForm.preset]?.name || bulkCleanForm.preset }}</span>
                                                    <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                                        <ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" />
                                                    </span>
                                                </ListboxButton>
                                                <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                                    <ListboxOption
                                                        v-for="(preset, key) in presets"
                                                        :key="key"
                                                        :value="key"
                                                        v-slot="{ active, selected }"
                                                    >
                                                        <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm', active ? 'bg-muted' : '']">
                                                            <span :class="['block', selected ? 'font-medium' : '']">{{ preset.name }}</span>
                                                            <span class="block text-xs text-muted-foreground">{{ preset.description }}</span>
                                                            <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                                <CheckIcon class="h-4 w-4" />
                                                            </span>
                                                        </li>
                                                    </ListboxOption>
                                                </ListboxOptions>
                                            </div>
                                        </Listbox>
                                    </div>

                                    <!-- LLM Options -->
                                    <div v-if="bulkCleanForm.mode === 'llm'" class="space-y-3">
                                        <!-- No authenticated providers warning -->
                                        <div v-if="!hasAuthenticatedProviders" class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-800 dark:bg-yellow-900/20">
                                            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                                                No LLM providers configured.
                                                <Link href="/settings/credentials" class="font-medium underline hover:no-underline">
                                                    Add API credentials
                                                </Link>
                                                to use AI cleaning.
                                            </p>
                                        </div>

                                        <template v-else>
                                            <div>
                                                <div class="mb-2 flex items-center justify-between">
                                                    <label class="block text-sm font-medium">Provider</label>
                                                    <Link href="/settings/credentials" class="text-xs text-muted-foreground hover:text-primary">
                                                        Add more
                                                    </Link>
                                                </div>
                                                <Listbox v-model="bulkCleanForm.llm_provider">
                                                    <div class="relative">
                                                        <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                                            <span class="block truncate">{{ authenticatedProviders[bulkCleanForm.llm_provider]?.name || bulkCleanForm.llm_provider }}</span>
                                                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                                <ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" />
                                                            </span>
                                                        </ListboxButton>
                                                        <ListboxOptions class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                                            <ListboxOption
                                                                v-for="(provider, key) in authenticatedProviders"
                                                                :key="key"
                                                                :value="key"
                                                                v-slot="{ active, selected }"
                                                            >
                                                                <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm whitespace-nowrap', active ? 'bg-muted' : '']">
                                                                    <span :class="['block', selected ? 'font-medium' : '']">{{ provider.name }}</span>
                                                                    <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
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
                                                <Listbox v-model="bulkCleanForm.llm_model" :disabled="loadingModels">
                                                    <div class="relative">
                                                        <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50">
                                                            <span class="block truncate">{{ providerModels.find(m => m.id === bulkCleanForm.llm_model)?.name || bulkCleanForm.llm_model }}</span>
                                                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                                <ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" />
                                                            </span>
                                                        </ListboxButton>
                                                        <ListboxOptions class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                                            <ListboxOption
                                                                v-for="model in providerModels"
                                                                :key="model.id"
                                                                :value="model.id"
                                                                v-slot="{ active, selected }"
                                                            >
                                                                <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm whitespace-nowrap', active ? 'bg-muted' : '']">
                                                                    <span :class="['block', selected ? 'font-medium' : '']">{{ model.name }}</span>
                                                                    <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
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

                                    <!-- Actions -->
                                    <div class="flex justify-end gap-3 pt-2">
                                        <button
                                            type="button"
                                            @click="showBulkCleanModal = false"
                                            class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="bulkCleanForm.processing || (bulkCleanForm.mode === 'llm' && !hasAuthenticatedProviders)"
                                            class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                        >
                                            <ArrowPathIcon v-if="bulkCleanForm.processing" class="h-4 w-4 animate-spin" />
                                            <SparklesIcon v-else class="h-4 w-4" />
                                            {{ bulkCleanForm.processing ? 'Starting...' : 'Start Cleaning' }}
                                        </button>
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
