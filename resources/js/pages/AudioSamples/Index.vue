<script setup lang="ts">
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import { CpuChipIcon } from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
import { getAudioSampleStatusClass, getAudioSampleStatusLabel } from '@/lib/audioSampleStatus';
import { getCleanRateCategoryClass } from '@/lib/cleanRate';
import { type BreadcrumbItem } from '@/types';
import type {
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
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Audio Samples', href: route('audio-samples.index') },
];

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const category = ref(props.filters.category || '');

// Selection state
const selectedIds = ref<Set<number>>(new Set());
const selectAll = ref(false);

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
        selectedIds.value = new Set(props.audioSamples.data.map((s) => s.id));
    }
    selectAll.value = !selectAll.value;
};

const updateSelectAll = () => {
    selectAll.value =
        props.audioSamples.data.length > 0 &&
        props.audioSamples.data.every((s) => selectedIds.value.has(s.id));
};

const selectedCount = computed(() => selectedIds.value.size);

// Bulk actions
const bulkDeleteForm = useForm({
    ids: [] as number[],
});

const submitBulkDelete = () => {
    if (!confirm(`Delete ${selectedCount.value} selected sample(s)? This cannot be undone.`)) {
        return;
    }

    bulkDeleteForm.ids = Array.from(selectedIds.value);
    bulkDeleteForm.delete(route('audio-samples.bulk-delete'), {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = new Set();
            selectAll.value = false;
        },
    });
};

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

// Smart pagination - show limited page numbers with ellipsis
const visiblePages = computed(() => {
    const current = props.audioSamples.current_page;
    const last = props.audioSamples.last_page;
    const delta = 2;
    const pages: (number | 'ellipsis')[] = [];

    pages.push(1);

    const rangeStart = Math.max(2, current - delta);
    const rangeEnd = Math.min(last - 1, current + delta);

    if (rangeStart > 2) {
        pages.push('ellipsis');
    }

    for (let i = rangeStart; i <= rangeEnd; i++) {
        pages.push(i);
    }

    if (rangeEnd < last - 1) {
        pages.push('ellipsis');
    }

    if (last > 1) {
        pages.push(last);
    }

    return pages;
});

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
                <Link :href="route('audio-samples.create')" class="w-full rounded-lg bg-primary px-4 py-2 text-center font-medium text-primary-foreground hover:bg-primary/90 sm:w-auto">
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
                        @click="selectedIds = new Set(); selectAll = false"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Clear selection
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
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
            <div class="rounded-xl border border-border bg-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-180">
                    <thead class="border-b border-border bg-muted/40">
                        <tr>
                            <th class="w-10 px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                                <input 
                                    type="checkbox" 
                                    :checked="selectAll"
                                    @change="toggleSelectAll"
                                    class="h-4 w-4 rounded border-border bg-background text-primary focus:ring-2 focus:ring-primary/30"
                                />
                            </th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Name</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Clean Rate</th>
                            <th class="hidden px-4 py-3 text-left text-sm font-medium md:table-cell">Method</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Status</th>
                            <th class="hidden px-4 py-3 text-left text-sm font-medium md:table-cell">Date</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr 
                            v-for="sample in audioSamples.data" 
                            :key="sample.id" 
                            :class="['hover:bg-muted/30', isSelected(sample.id) ? 'bg-primary/5' : '']"
                        >
                            <td class="px-4 py-4">
                                <input 
                                    type="checkbox" 
                                    :checked="isSelected(sample.id)"
                                    @change="toggleSelection(sample.id)"
                                    class="h-4 w-4 rounded border-border bg-background text-primary focus:ring-2 focus:ring-primary/30"
                                />
                            </td>
                            <td class="px-4 py-4">
                                <div class="min-w-0">
                                    <Link
                                        :href="route('audio-samples.show', { audioSample: sample.id })"
                                        class="block truncate font-medium hover:text-primary transition-colors"
                                    >
                                        {{ sample.name }}
                                    </Link>
                                </div>
                            </td>
                                <td class="px-4 py-4">
                                <span v-if="sample.base_transcription?.clean_rate !== null && sample.base_transcription?.clean_rate !== undefined" :class="['inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap', getCleanRateCategoryFromValue(sample.base_transcription?.clean_rate)]">
                                    {{ sample.base_transcription?.clean_rate }}%
                                </span>
                                <span v-else class="text-muted-foreground">-</span>
                            </td>
                            <td class="hidden px-4 py-4 md:table-cell">
                                <template v-if="getMethodDisplay(sample.processing_run)">
                                    <div class="flex min-w-0 items-center gap-1.5 text-sm">
                                        <SparklesIcon
                                            v-if="getMethodDisplay(sample.processing_run)?.mode === 'llm'"
                                            class="h-3.5 w-3.5 text-secondary"
                                        />
                                        <CpuChipIcon
                                            v-else
                                            class="h-3.5 w-3.5 text-primary"
                                        />
                                        <span class="truncate text-muted-foreground capitalize">
                                            {{ getMethodDisplay(sample.processing_run)?.text }}
                                        </span>
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground">-</span>
                            </td>
                            <td class="px-4 py-4">
                                <span :class="['inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium whitespace-nowrap', getAudioSampleStatusClass(sample.status)]">
                                    {{ getAudioSampleStatusLabel(sample.status) }}
                                </span>
                            </td>
                            <td class="hidden px-4 py-4 text-sm text-muted-foreground md:table-cell whitespace-nowrap">
                                {{ sample.created_at }}
                            </td>
                            <td class="px-4 py-4">
                                <Link :href="route('audio-samples.show', { audioSample: sample.id })" class="text-sm font-medium text-primary hover:text-primary/80">
                                    View
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="audioSamples.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                                No audio samples found
                            </td>
                        </tr>
                    </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="audioSamples.last_page > 1" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <span class="text-sm text-muted-foreground">
                    Showing {{ (audioSamples.current_page - 1) * audioSamples.per_page + 1 }} to 
                    {{ Math.min(audioSamples.current_page * audioSamples.per_page, audioSamples.total) }} of 
                    {{ audioSamples.total }} audio samples
                </span>
                <div class="flex flex-wrap gap-1">
                    <button 
                        @click="goToPage(audioSamples.current_page - 1)"
                        :disabled="audioSamples.current_page === 1"
                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-50 hover:bg-muted"
                    >
                        Previous
                    </button>
                    <template v-for="(page, idx) in visiblePages" :key="idx">
                        <span v-if="page === 'ellipsis'" class="px-2 py-1 text-muted-foreground">...</span>
                        <button 
                            v-else
                            @click="goToPage(page)"
                            :class="['rounded-lg px-3 py-1 text-sm', page === audioSamples.current_page ? 'bg-primary text-primary-foreground' : 'border hover:bg-muted']"
                        >
                            {{ page }}
                        </button>
                    </template>
                    <button 
                        @click="goToPage(audioSamples.current_page + 1)"
                        :disabled="audioSamples.current_page === audioSamples.last_page"
                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-50 hover:bg-muted"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
