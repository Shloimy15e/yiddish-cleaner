<script setup lang="ts">
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import { SparklesIcon, CpuChipIcon } from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface ProcessingRun {
    preset: string;
    mode: 'rule' | 'llm';
    llm_provider: string | null;
    llm_model: string | null;
}

interface AudioSample {
    id: number;
    name: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    status: string;
    validated_at: string | null;
    created_at: string;
    processing_run: ProcessingRun | null;
}

const props = defineProps<{
    audioSamples: {
        data: AudioSample[];
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

const statusOptions = [
    { value: '', label: 'All Status' },
    { value: 'pending_transcript', label: 'Needs Transcript' },
    { value: 'imported', label: 'Needs Cleaning' },
    { value: 'cleaned', label: 'Ready for Review' },
    { value: 'validated', label: 'Benchmark Ready' },
    { value: 'failed', label: 'Failed' },
];

const categoryOptions = [
    { value: '', label: 'All Categories' },
    { value: 'excellent', label: 'Excellent (90%+)' },
    { value: 'good', label: 'Good (75-89%)' },
    { value: 'moderate', label: 'Moderate (50-74%)' },
    { value: 'low', label: 'Low (25-49%)' },
    { value: 'poor', label: 'Poor (<25%)' },
];

const selectedStatus = computed(() => statusOptions.find(o => o.value === statusFilter.value) || statusOptions[0]);
const selectedCategory = computed(() => categoryOptions.find(o => o.value === category.value) || categoryOptions[0]);

// Selection helpers
const isSelected = (id: number) => selectedIds.value.has(id);
const toggleSelection = (id: number) => {
    if (selectedIds.value.has(id)) {
        selectedIds.value.delete(id);
    } else {
        selectedIds.value.add(id);
    }
    selectedIds.value = new Set(selectedIds.value); // Trigger reactivity
    updateSelectAll();
};
const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedIds.value = new Set();
    } else {
        selectedIds.value = new Set(props.audioSamples.data.map(s => s.id));
    }
    selectAll.value = !selectAll.value;
};
const updateSelectAll = () => {
    selectAll.value = props.audioSamples.data.length > 0 && 
        props.audioSamples.data.every(s => selectedIds.value.has(s.id));
};
const selectedCount = computed(() => selectedIds.value.size);

// Get samples that can be bulk cleaned (status = imported)
const selectedForCleaning = computed(() => {
    return props.audioSamples.data.filter(s => 
        selectedIds.value.has(s.id)
    );
});

// Bulk actions
const bulkCleanForm = useForm({
    ids: [] as number[],
    preset: 'titles_only',
    mode: 'rule' as 'rule' | 'llm',
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
});

const submitBulkClean = () => {
    bulkCleanForm.ids = Array.from(selectedIds.value);
    bulkCleanForm.post(route('audio-samples.bulk-clean'), {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = new Set();
            selectAll.value = false;
        },
    });
};

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
        pending_transcript: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        imported: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        cleaning: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        cleaned: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        validated: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
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

// Get display text for cleaning method
const getMethodDisplay = (run: ProcessingRun | null) => {
    if (!run) return null;
    if (run.mode === 'llm' && run.llm_model) {
        // Extract model name from full path (e.g., "anthropic/claude-sonnet-4" -> "claude-sonnet-4")
        const modelName = run.llm_model.split('/').pop() || run.llm_model;
        return { mode: 'llm', text: modelName, provider: run.llm_provider };
    }
    if (run.preset) {
        return { mode: 'rule', text: run.preset.replace(/_/g, ' ') };
    }
    return null;
};

const applyFilters = () => {
    router.get(route('audio-samples.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        category: category.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
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
    router.get(route('audio-samples.index'), {
        ...props.filters,
        page,
    }, {
        preserveState: true,
    });
};

// Smart pagination - show limited page numbers with ellipsis
const visiblePages = computed(() => {
    const current = props.audioSamples.current_page;
    const last = props.audioSamples.last_page;
    const delta = 2; // pages to show on each side of current
    const pages: (number | 'ellipsis')[] = [];
    
    // Always show first page
    pages.push(1);
    
    // Calculate range around current page
    const rangeStart = Math.max(2, current - delta);
    const rangeEnd = Math.min(last - 1, current + delta);
    
    // Add ellipsis if needed before range
    if (rangeStart > 2) {
        pages.push('ellipsis');
    }
    
    // Add pages in range
    for (let i = rangeStart; i <= rangeEnd; i++) {
        pages.push(i);
    }
    
    // Add ellipsis if needed after range
    if (rangeEnd < last - 1) {
        pages.push('ellipsis');
    }
    
    // Always show last page (if more than 1 page)
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
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Audio Samples</h1>
                <Link :href="route('audio-samples.create')" class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90">
                    Import New
                </Link>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-4">
                <input 
                    v-model="search"
                    type="text" 
                    placeholder="Search audio samples..."
                    class="rounded-lg border bg-background px-4 py-2 w-64"
                />
                
                <!-- Status Filter -->
                <Listbox :model-value="selectedStatus" @update:model-value="setStatus">
                    <div class="relative w-48">
                        <ListboxButton class="relative w-full cursor-pointer rounded-lg border bg-background py-2 pl-4 pr-10 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <span class="block truncate">{{ selectedStatus.label }}</span>
                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            </span>
                        </ListboxButton>
                        <transition
                            leave-active-class="transition duration-100 ease-in"
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
                    <div class="relative w-48">
                        <ListboxButton class="relative w-full cursor-pointer rounded-lg border bg-background py-2 pl-4 pr-10 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                            <span class="block truncate">{{ selectedCategory.label }}</span>
                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" aria-hidden="true" />
                            </span>
                        </ListboxButton>
                        <transition
                            leave-active-class="transition duration-100 ease-in"
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
            <div v-if="selectedCount > 0" class="rounded-xl border-2 border-primary bg-primary/5 p-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <span class="font-medium">{{ selectedCount }} selected</span>
                    <button 
                        @click="selectedIds = new Set(); selectAll = false"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Clear selection
                    </button>
                </div>
                <div class="flex items-center gap-2">
                    <span v-if="selectedForCleaning.length > 0" class="text-sm text-muted-foreground">
                        {{ selectedForCleaning.length }} can be cleaned
                    </span>
                    <button 
                        v-if="selectedForCleaning.length > 0"
                        @click="submitBulkClean"
                        :disabled="bulkCleanForm.processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 text-white px-4 py-2 font-medium hover:bg-blue-700 disabled:opacity-50"
                    >
                        <SparklesIcon class="w-4 h-4" />
                        {{ bulkCleanForm.processing ? 'Cleaning...' : 'Bulk Clean' }}
                    </button>
                </div>
            </div>

            <!-- Audio Samples Table -->
            <div class="rounded-xl border bg-card overflow-hidden">
                <table class="w-full">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium w-10">
                                <input 
                                    type="checkbox" 
                                    :checked="selectAll"
                                    @change="toggleSelectAll"
                                    class="rounded border-gray-300"
                                />
                            </th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Name</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Clean Rate</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Method</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Status</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Date</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr 
                            v-for="sample in audioSamples.data" 
                            :key="sample.id" 
                            :class="['hover:bg-muted/30', isSelected(sample.id) ? 'bg-primary/5' : '']"
                        >
                            <td class="px-4 py-3">
                                <input 
                                    type="checkbox" 
                                    :checked="isSelected(sample.id)"
                                    @change="toggleSelection(sample.id)"
                                    class="rounded border-gray-300"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <Link :href="route('audio-samples.show', { audioSample: sample.id })" class="font-medium hover:underline">
                                    {{ sample.name }}
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="sample.clean_rate !== null" :class="['rounded-full px-2 py-1 text-xs font-medium', getCategoryColor(sample.clean_rate_category)]">
                                    {{ sample.clean_rate }}%
                                </span>
                                <span v-else class="text-muted-foreground">-</span>
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="getMethodDisplay(sample.processing_run)">
                                    <span 
                                        v-if="getMethodDisplay(sample.processing_run)?.mode === 'llm'" 
                                        class="inline-flex items-center gap-1.5 text-sm"
                                    >
                                        <SparklesIcon class="w-3.5 h-3.5 text-purple-500" />
                                        <span class="text-muted-foreground capitalize">{{ getMethodDisplay(sample.processing_run)?.text }}</span>
                                    </span>
                                    <span 
                                        v-else 
                                        class="inline-flex items-center gap-1.5 text-sm"
                                    >
                                        <CpuChipIcon class="w-3.5 h-3.5 text-blue-500" />
                                        <span class="text-muted-foreground capitalize">{{ getMethodDisplay(sample.processing_run)?.text }}</span>
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground">-</span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium', getStatusColor(sample.status)]">
                                    {{ getStatusLabel(sample.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">
                                {{ sample.created_at }}
                            </td>
                            <td class="px-4 py-3">
                                <Link :href="route('audio-samples.show', { audioSample: sample.id })" class="text-sm text-primary hover:underline">
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

            <!-- Pagination -->
            <div v-if="audioSamples.last_page > 1" class="flex items-center justify-between">
                <span class="text-sm text-muted-foreground">
                    Showing {{ (audioSamples.current_page - 1) * audioSamples.per_page + 1 }} to 
                    {{ Math.min(audioSamples.current_page * audioSamples.per_page, audioSamples.total) }} of 
                    {{ audioSamples.total }} audio samples
                </span>
                <div class="flex gap-1">
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
