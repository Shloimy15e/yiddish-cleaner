<script setup lang="ts">
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface AudioSample {
    id: number;
    name: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    status: string;
    validated_at: string | null;
    created_at: string;
    processing_run: {
        preset: string;
        mode: string;
    } | null;
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
        validated?: string;
        category?: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Audio Samples', href: route('audio-samples.index') },
];

const search = ref(props.filters.search || '');
const validated = ref(props.filters.validated || '');
const category = ref(props.filters.category || '');

const statusOptions = [
    { value: '', label: 'All Status' },
    { value: 'yes', label: 'Validated' },
    { value: 'no', label: 'Not Validated' },
];

const categoryOptions = [
    { value: '', label: 'All Categories' },
    { value: 'excellent', label: 'Excellent (90%+)' },
    { value: 'good', label: 'Good (75-89%)' },
    { value: 'moderate', label: 'Moderate (50-74%)' },
    { value: 'low', label: 'Low (25-49%)' },
    { value: 'poor', label: 'Poor (<25%)' },
];

const selectedStatus = computed(() => statusOptions.find(o => o.value === validated.value) || statusOptions[0]);
const selectedCategory = computed(() => categoryOptions.find(o => o.value === category.value) || categoryOptions[0]);

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

const applyFilters = () => {
    router.get(route('audio-samples.index'), {
        search: search.value || undefined,
        validated: validated.value || undefined,
        category: category.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

const setValidated = (option: { value: string }) => {
    validated.value = option.value;
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
                <Listbox :model-value="selectedStatus" @update:model-value="setValidated">
                    <div class="relative w-44">
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

            <!-- Audio Samples Table -->
            <div class="rounded-xl border bg-card overflow-hidden">
                <table class="w-full">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium">Name</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Clean Rate</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Preset</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Status</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Date</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="sample in audioSamples.data" :key="sample.id" class="hover:bg-muted/30">
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
                            <td class="px-4 py-3 text-sm text-muted-foreground">
                                {{ sample.processing_run?.preset?.replace(/_/g, ' ') || '-' }}
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="sample.status === 'failed'" class="inline-flex items-center gap-1 text-red-600 font-medium">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                                    </svg>
                                    Failed
                                </span>
                                <span v-else-if="sample.validated_at" class="inline-flex items-center gap-1 text-green-600">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                    Validated
                                </span>
                                <span v-else-if="sample.status === 'processing'" class="text-blue-600">Processing</span>
                                <span v-else class="text-muted-foreground">Pending Validation</span>
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
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
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
                <div class="flex gap-2">
                    <button 
                        @click="goToPage(audioSamples.current_page - 1)"
                        :disabled="audioSamples.current_page === 1"
                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-50"
                    >
                        Previous
                    </button>
                    <button 
                        v-for="page in audioSamples.last_page" 
                        :key="page"
                        @click="goToPage(page)"
                        :class="['rounded-lg px-3 py-1 text-sm', page === audioSamples.current_page ? 'bg-primary text-primary-foreground' : 'border hover:bg-muted']"
                    >
                        {{ page }}
                    </button>
                    <button 
                        @click="goToPage(audioSamples.current_page + 1)"
                        :disabled="audioSamples.current_page === audioSamples.last_page"
                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-50"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
