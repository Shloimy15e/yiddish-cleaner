<script setup lang="ts">
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import { CpuChipIcon, MicrophoneIcon, ArrowPathIcon, SparklesIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import type { ColumnDef } from '@/components/ui/data-table/types';
import { getAudioSampleStatusClass, getAudioSampleStatusLabel } from '@/lib/audioSampleStatus';
import { getCleanRateCategoryClass } from '@/lib/cleanRate';
import { formatCreatedBy } from '@/lib/createdBy';
import { type BreadcrumbItem } from '@/types';
import type {
    AsrProvider,
    AudioSampleListItem,
    AudioSampleProcessingRunSummary,
} from '@/types/audio-samples';

const props = defineProps<{
    audioSamples: {
        data: AudioSampleListItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search?: string;
        status?: string;
        category?: string;
    };
    asrProviders?: Record<string, AsrProvider>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Audio Samples', href: route('audio-samples.index') },
];

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const category = ref(props.filters.category || '');

// Selection via composable
const { selectedIds, selectedCount, clearSelection, selectedArray } = useTableSelection(
    computed(() => props.audioSamples.data),
);

// Column definitions
const columns: ColumnDef<AudioSampleListItem>[] = [
    { key: 'name', label: 'Name' },
    { key: 'clean_rate', label: 'Clean Rate' },
    { key: 'method', label: 'Method', hideBelow: 'hidden md:table-cell' },
    { key: 'status', label: 'Status' },
    { key: 'created_by', label: 'Created By', hideBelow: 'hidden md:table-cell' },
    { key: 'created_at', label: 'Date', hideBelow: 'hidden md:table-cell' },
    { key: 'actions', label: 'Actions' },
];

// Derive clean rate category from value
const getCleanRateCategoryFromValue = (rate: number | null | undefined): string => {
    if (rate === null || rate === undefined) return '';
    if (rate >= 90) return getCleanRateCategoryClass('excellent');
    if (rate >= 70) return getCleanRateCategoryClass('good');
    if (rate >= 50) return getCleanRateCategoryClass('fair');
    return getCleanRateCategoryClass('needs-work');
};

const statusOptions = [
    { value: '', label: 'All Status' },
    { value: 'pending_base', label: 'Needs Transcript' },
    { value: 'unclean', label: 'Needs Cleaning' },
    { value: 'ready', label: 'Benchmark Ready' },
    { value: 'benchmarked', label: 'Benchmarked' },
    { value: 'draft', label: 'Draft' },
];

const categoryOptions = [
    { value: '', label: 'All Categories' },
    { value: 'excellent', label: 'Excellent (90%+)' },
    { value: 'good', label: 'Good (75-89%)' },
    { value: 'moderate', label: 'Moderate (50-74%)' },
    { value: 'low', label: 'Low (25-49%)' },
    { value: 'poor', label: 'Poor (<25%)' },
];

const selectedStatus = computed(
    () => statusOptions.find((o) => o.value === statusFilter.value) || statusOptions[0],
);
const selectedCategory = computed(
    () => categoryOptions.find((o) => o.value === category.value) || categoryOptions[0],
);

// Bulk actions
const bulkDeleteForm = useForm({
    ids: [] as number[],
});

const submitBulkDelete = () => {
    if (!confirm(`Delete ${selectedCount.value} selected sample(s)? This cannot be undone.`)) {
        return;
    }

    bulkDeleteForm.ids = selectedArray.value as number[];
    bulkDeleteForm.delete(route('audio-samples.bulk-delete'), {
        preserveScroll: true,
        onSuccess: () => clearSelection(),
    });
};

// Bulk transcription modal state
const showBulkTranscribeModal = ref(false);
const bulkTranscribeForm = useForm({
    ids: [] as number[],
    provider: 'yiddishlabs',
    model: '',
});

// ASR providers/models (loaded via optional prop)
const loadingProviders = ref(false);

// Only show providers that user has authenticated
const authenticatedAsrProviders = computed(() => {
    return Object.fromEntries(
        Object.entries(props.asrProviders ?? {}).filter(([, provider]) => provider.has_credential)
    );
});

const hasAuthenticatedAsrProviders = computed(() => Object.keys(authenticatedAsrProviders.value).length > 0);

const currentProviderModels = computed(() => {
    const provider = (props.asrProviders ?? {})[bulkTranscribeForm.provider];
    return provider?.models || [];
});

watch(
    () => bulkTranscribeForm.provider,
    (newProvider) => {
        const provider = (props.asrProviders ?? {})[newProvider];
        if (provider) {
            bulkTranscribeForm.model = provider.default_model;
        }
    }
);

// Initialize form defaults when providers load
watch(
    () => props.asrProviders,
    (providers) => {
        if (!providers) return;
        loadingProviders.value = false;
        const authKeys = Object.keys(authenticatedAsrProviders.value);
        if (authKeys.length > 0 && !authenticatedAsrProviders.value[bulkTranscribeForm.provider]) {
            bulkTranscribeForm.provider = authKeys[0];
        }
        const provider = providers[bulkTranscribeForm.provider];
        if (provider) {
            bulkTranscribeForm.model = provider.default_model;
        }
    },
);

const openBulkTranscribeModal = () => {
    showBulkTranscribeModal.value = true;
    loadingProviders.value = true;
    router.reload({ only: ['asrProviders'] });
};

const submitBulkTranscribe = () => {
    bulkTranscribeForm.ids = selectedArray.value as number[];
    bulkTranscribeForm.post(route('audio-samples.bulk-transcribe'), {
        preserveScroll: true,
        onSuccess: () => {
            showBulkTranscribeModal.value = false;
            clearSelection();
            bulkTranscribeForm.reset();
        },
    });
};

// Count selected items that are eligible for transcription (status: ready)
const selectedEligibleCount = computed(() => {
    return props.audioSamples.data.filter(
        (s) => selectedIds.value.has(s.id) && s.status === 'ready'
    ).length;
});

// Get display text for cleaning method
const getMethodDisplay = (run: AudioSampleProcessingRunSummary | null) => {
    if (!run) return null;
    if (run.mode === 'llm' && run.llm_model) {
        const modelName = run.llm_model.split('/').pop() || run.llm_model;
        return { mode: 'llm', text: modelName, provider: run.llm_provider };
    }
    if (run.preset) {
        return { mode: 'rule', text: run.preset.replace(/_/g, ' ') };
    }
    return null;
};

const applyFilters = () => {
    router.get(
        route('audio-samples.index'),
        {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
            category: category.value || undefined,
        },
        {
            preserveState: true,
            replace: true,
        },
    );
};

const setStatus = (option: { value: string }) => {
    statusFilter.value = option.value;
    applyFilters();
};

const setCategory = (option: { value: string }) => {
    category.value = option.value;
    applyFilters();
};

const goToPage = (page: number) => {
    router.get(
        route('audio-samples.index'),
        {
            ...props.filters,
            page,
        },
        {
            preserveState: true,
        },
    );
};

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout>;
watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(applyFilters, 300);
});
</script>

<template>
    <Head title="Audio Samples" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="text-2xl font-bold">Audio Samples</h1>
                <Link :href="route('imports.create')" class="w-full rounded-lg bg-primary px-4 py-2 text-center font-medium text-primary-foreground hover:bg-primary/90 sm:w-auto">
                    Import New
                </Link>
            </div>

            <!-- Filters -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search audio samples..."
                    class="h-11 w-full rounded-lg border border-border bg-muted px-4 text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                />

                <!-- Status Filter -->
                <Listbox :model-value="selectedStatus" @update:model-value="setStatus">
                    <div class="relative w-full">
                        <ListboxButton class="relative h-11 w-full cursor-pointer rounded-lg border border-border bg-muted py-2 pl-4 pr-10 text-left text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <span class="block truncate">{{ selectedStatus.label }}</span>
                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            </span>
                        </ListboxButton>
                        <transition
                            leave-active-class="transition ease-in duration-100"
                            leave-from-class="opacity-100"
                            leave-to-class="opacity-0"
                        >
                            <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-popover py-1 shadow-lg focus:outline-none">
                                <ListboxOption
                                    v-for="option in statusOptions"
                                    :key="option.value"
                                    :value="option"
                                    v-slot="{ active, selected }"
                                    as="template"
                                >
                                    <li :class="[active ? 'bg-accent' : '', 'relative cursor-pointer select-none py-2 pl-10 pr-4']">
                                        <span :class="[selected ? 'font-medium' : 'font-normal', 'block truncate']">
                                            {{ option.label }}
                                        </span>
                                        <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                            <CheckIcon class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                    </li>
                                </ListboxOption>
                            </ListboxOptions>
                        </transition>
                    </div>
                </Listbox>

                <!-- Category Filter -->
                <Listbox :model-value="selectedCategory" @update:model-value="setCategory">
                    <div class="relative w-full">
                        <ListboxButton class="relative h-11 w-full cursor-pointer rounded-lg border border-border bg-muted py-2 pl-4 pr-10 text-left text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <span class="block truncate">{{ selectedCategory.label }}</span>
                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            </span>
                        </ListboxButton>
                        <transition
                            leave-active-class="transition ease-in duration-100"
                            leave-from-class="opacity-100"
                            leave-to-class="opacity-0"
                        >
                            <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-popover py-1 shadow-lg focus:outline-none">
                                <ListboxOption
                                    v-for="option in categoryOptions"
                                    :key="option.value"
                                    :value="option"
                                    v-slot="{ active, selected }"
                                    as="template"
                                >
                                    <li :class="[active ? 'bg-accent' : '', 'relative cursor-pointer select-none py-2 pl-10 pr-4']">
                                        <span :class="[selected ? 'font-medium' : 'font-normal', 'block truncate']">
                                            {{ option.label }}
                                        </span>
                                        <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                            <CheckIcon class="h-5 w-5" aria-hidden="true" />
                                        </span>
                                    </li>
                                </ListboxOption>
                            </ListboxOptions>
                        </transition>
                    </div>
                </Listbox>
            </div>

            <!-- Bulk Action Bar (when items selected) -->
            <div v-if="selectedCount > 0" class="rounded-xl border-2 border-primary bg-primary/5 p-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-medium">{{ selectedCount }} selected</span>
                    <button
                        @click="clearSelection"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Clear selection
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        @click="openBulkTranscribeModal"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary text-primary-foreground px-4 py-2 font-medium hover:bg-primary/90"
                    >
                        <MicrophoneIcon class="h-4 w-4" />
                        Bulk Transcribe
                    </button>
                    <button
                        @click="submitBulkDelete"
                        :disabled="bulkDeleteForm.processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-red-600 text-white px-4 py-2 font-medium hover:bg-red-700 disabled:opacity-50"
                    >
                        {{ bulkDeleteForm.processing ? 'Deleting...' : 'Bulk Delete' }}
                    </button>
                </div>
            </div>

            <!-- Audio Samples Table -->
            <DataTable
                :columns="columns"
                :items="audioSamples.data"
                selectable
                v-model:selected="selectedIds"
                empty-message="No audio samples found"
                table-class="min-w-180"
            >
                <template #cell-name="{ item }">
                    <div class="min-w-0">
                        <Link
                            :href="route('audio-samples.show', { audioSample: item.id })"
                            class="block truncate font-medium hover:text-primary transition-colors"
                        >
                            {{ item.name }}
                        </Link>
                    </div>
                </template>

                <template #cell-clean_rate="{ item }">
                    <span v-if="item.base_transcription?.clean_rate !== null && item.base_transcription?.clean_rate !== undefined" :class="['inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap', getCleanRateCategoryFromValue(item.base_transcription?.clean_rate)]">
                        {{ item.base_transcription?.clean_rate }}%
                    </span>
                    <span v-else class="text-muted-foreground">-</span>
                </template>

                <template #cell-method="{ item }">
                    <template v-if="getMethodDisplay(item.processing_run)">
                        <div class="flex min-w-0 items-center gap-1.5 text-sm">
                            <SparklesIcon
                                v-if="getMethodDisplay(item.processing_run)?.mode === 'llm'"
                                class="h-3.5 w-3.5 text-secondary"
                            />
                            <CpuChipIcon
                                v-else
                                class="h-3.5 w-3.5 text-primary"
                            />
                            <span class="truncate text-muted-foreground capitalize">
                                {{ getMethodDisplay(item.processing_run)?.text }}
                            </span>
                        </div>
                    </template>
                    <span v-else class="text-muted-foreground">-</span>
                </template>

                <template #cell-status="{ item }">
                    <span :class="['inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium whitespace-nowrap', getAudioSampleStatusClass(item.status)]">
                        {{ getAudioSampleStatusLabel(item.status) }}
                    </span>
                </template>

                <template #cell-created_by="{ item }">
                    <span class="text-sm text-muted-foreground whitespace-nowrap">
                        {{ formatCreatedBy(item.user, undefined) }}
                    </span>
                </template>

                <template #cell-created_at="{ item }">
                    <span class="text-sm text-muted-foreground whitespace-nowrap">{{ item.created_at }}</span>
                </template>

                <template #cell-actions="{ item }">
                    <Link :href="route('audio-samples.show', { audioSample: item.id })" class="text-sm font-medium text-primary hover:text-primary/80">
                        View
                    </Link>
                </template>
            </DataTable>

            <!-- Pagination -->
            <TablePagination
                :current-page="audioSamples.current_page"
                :last-page="audioSamples.last_page"
                :per-page="audioSamples.per_page"
                :total="audioSamples.total"
                noun="audio samples"
                @page-change="goToPage"
            />
        </div>

        <!-- Bulk Transcribe Modal -->
        <Modal :show="showBulkTranscribeModal" max-width="md" @close="showBulkTranscribeModal = false">
            <template #title>Bulk Transcribe Audio Samples</template>

                                <form @submit.prevent="submitBulkTranscribe" class="space-y-4 p-6">
                                    <!-- Selection summary -->
                                    <div class="rounded-lg bg-muted/50 p-3 text-sm">
                                        <p>
                                            <span class="font-medium">{{ selectedCount }}</span> audio sample(s) selected
                                        </p>
                                        <p v-if="selectedEligibleCount < selectedCount" class="text-muted-foreground mt-1">
                                            {{ selectedEligibleCount }} eligible for transcription (status: ready)
                                        </p>
                                    </div>

                                    <!-- Loading state -->
                                    <div v-if="loadingProviders" class="flex items-center justify-center py-4">
                                        <ArrowPathIcon class="h-5 w-5 animate-spin text-muted-foreground" />
                                        <span class="ml-2 text-sm text-muted-foreground">Loading providers...</span>
                                    </div>

                                    <!-- No authenticated providers warning -->
                                    <div v-else-if="!hasAuthenticatedAsrProviders" class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-800 dark:bg-yellow-900/20">
                                        <p class="text-sm text-yellow-800 dark:text-yellow-200">
                                            No ASR providers configured.
                                            <Link :href="route('settings.credentials')" class="font-medium underline hover:no-underline">
                                                Add API credentials
                                            </Link>
                                            to use transcription.
                                        </p>
                                    </div>

                                    <template v-else>
                                        <!-- Provider Selection -->
                                        <div>
                                            <div class="mb-2 flex items-center justify-between">
                                                <label class="block text-sm font-medium">ASR Provider</label>
                                                <Link :href="route('settings.credentials')" class="text-xs text-muted-foreground hover:text-primary">
                                                    Add more
                                                </Link>
                                            </div>
                                            <Listbox v-model="bulkTranscribeForm.provider">
                                                <div class="relative">
                                                    <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                                        <span class="block truncate">{{ authenticatedAsrProviders[bulkTranscribeForm.provider]?.name || bulkTranscribeForm.provider }}</span>
                                                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                            <ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" />
                                                        </span>
                                                    </ListboxButton>
                                                    <ListboxOptions class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                                        <ListboxOption
                                                            v-for="(provider, key) in authenticatedAsrProviders"
                                                            :key="key"
                                                            :value="key"
                                                            v-slot="{ active, selected }"
                                                        >
                                                            <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm', active ? 'bg-muted' : '']">
                                                                <span :class="['block', selected ? 'font-medium' : '']">{{ provider.name }}</span>
                                                                <span class="block text-xs text-muted-foreground">{{ provider.description }}</span>
                                                                <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                                    <CheckIcon class="h-4 w-4" />
                                                                </span>
                                                            </li>
                                                        </ListboxOption>
                                                    </ListboxOptions>
                                                </div>
                                            </Listbox>
                                        </div>

                                        <!-- Model Selection -->
                                        <div>
                                            <label class="mb-2 block text-sm font-medium">Model</label>
                                            <Listbox v-model="bulkTranscribeForm.model">
                                                <div class="relative">
                                                    <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                                        <span class="block truncate">{{ currentProviderModels.find(m => m.id === bulkTranscribeForm.model)?.name || bulkTranscribeForm.model }}</span>
                                                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                            <ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" />
                                                        </span>
                                                    </ListboxButton>
                                                    <ListboxOptions class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                                        <ListboxOption
                                                            v-for="model in currentProviderModels"
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

                                    <!-- Actions -->
                                    <div class="flex justify-end gap-3 pt-2">
                                        <button
                                            type="button"
                                            @click="showBulkTranscribeModal = false"
                                            class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            :disabled="bulkTranscribeForm.processing || !hasAuthenticatedAsrProviders || loadingProviders"
                                            class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                        >
                                            <ArrowPathIcon v-if="bulkTranscribeForm.processing" class="h-4 w-4 animate-spin" />
                                            <MicrophoneIcon v-else class="h-4 w-4" />
                                            {{ bulkTranscribeForm.processing ? 'Starting...' : 'Start Transcription' }}
                                        </button>
                                    </div>
                                </form>
        </Modal>
    </AppLayout>
</template>
