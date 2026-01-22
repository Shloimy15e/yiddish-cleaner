<script setup lang="ts">
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import { 
    DocumentTextIcon,
    LinkIcon,
    CheckCircleIcon,
    PlusIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { TranscriptionListItem } from '@/types/transcriptions';

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
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Base Transcriptions', href: '/transcriptions' },
];

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const linkedFilter = ref(props.filters.linked || '');

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

// Format date
const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
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

            <!-- Results count -->
            <div class="text-sm text-muted-foreground">
                {{ transcriptions.total }} transcription{{ transcriptions.total !== 1 ? 's' : '' }}
            </div>

            <!-- Table -->
            <div class="rounded-xl border bg-card overflow-hidden">
                <table class="w-full">
                    <thead class="border-b bg-muted/50">
                        <tr>
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
                            class="hover:bg-muted/50 transition-colors"
                        >
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
                            <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
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
    </AppLayout>
</template>
