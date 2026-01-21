<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface ProcessingRun {
    id: number;
    status: string;
    source_type: string | null;
    source_url: string | null;
    total: number;
    completed: number;
    failed: number;
    created_at: string;
}

const props = defineProps<{
    runs: {
        data: ProcessingRun[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Import Runs', href: route('audio-samples.runs') },
];

const getStatusLabel = (status: string) => {
    const labels: Record<string, string> = {
        pending: 'Pending',
        processing: 'Processing',
        completed: 'Completed',
        completed_with_errors: 'Completed (Errors)',
        failed: 'Failed',
    };
    return labels[status] ?? status;
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        completed_with_errors: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    };
    return colors[status] ?? 'bg-muted text-muted-foreground';
};

const progressPercent = (run: ProcessingRun) => {
    if (!run.total) return 0;
    return Math.min(100, Math.round(((run.completed + run.failed) / run.total) * 100));
};

const formatDate = (value: string) => new Date(value).toLocaleString();

const visiblePages = computed(() => {
    const current = props.runs.current_page;
    const last = props.runs.last_page;
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

const goToPage = (page: number) => {
    router.get(route('audio-samples.runs'), { page }, { preserveState: true });
};
</script>

<template>
    <Head title="Import Runs" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Import Runs</h1>
                <Link
                    :href="route('audio-samples.create')"
                    class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                >
                    Start Import
                </Link>
            </div>

            <div class="rounded-xl border bg-card overflow-hidden">
                <table class="w-full">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium">Run</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Source</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Progress</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Status</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Started</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="run in runs.data" :key="run.id" class="hover:bg-muted/30">
                            <td class="px-4 py-3">
                                <div class="font-medium">#{{ run.id }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ run.completed + run.failed }} / {{ run.total || 0 }} processed
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm capitalize">{{ run.source_type || 'sheet' }}</div>
                                <div v-if="run.source_url" class="text-xs text-muted-foreground truncate max-w-[260px]">
                                    {{ run.source_url }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="h-2 w-full rounded-full bg-muted">
                                    <div
                                        class="h-2 rounded-full bg-primary transition-all"
                                        :style="{ width: `${progressPercent(run)}%` }"
                                    ></div>
                                </div>
                                <div class="text-xs text-muted-foreground mt-1">{{ progressPercent(run) }}%</div>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium', getStatusColor(run.status)]">
                                    {{ getStatusLabel(run.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">
                                {{ formatDate(run.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <Link :href="route('audio-samples.run', run.id)" class="text-sm text-primary hover:underline">
                                    View
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="runs.data.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                No import runs yet
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="runs.last_page > 1" class="flex items-center justify-between">
                <span class="text-sm text-muted-foreground">
                    Showing {{ (runs.current_page - 1) * runs.per_page + 1 }} to
                    {{ Math.min(runs.current_page * runs.per_page, runs.total) }} of
                    {{ runs.total }} runs
                </span>
                <div class="flex gap-1">
                    <button
                        @click="goToPage(runs.current_page - 1)"
                        :disabled="runs.current_page === 1"
                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-50 hover:bg-muted"
                    >
                        Previous
                    </button>
                    <template v-for="(page, idx) in visiblePages" :key="idx">
                        <span v-if="page === 'ellipsis'" class="px-2 py-1 text-muted-foreground">...</span>
                        <button
                            v-else
                            @click="goToPage(page)"
                            :class="['rounded-lg px-3 py-1 text-sm', page === runs.current_page ? 'bg-primary text-primary-foreground' : 'border hover:bg-muted']"
                        >
                            {{ page }}
                        </button>
                    </template>
                    <button
                        @click="goToPage(runs.current_page + 1)"
                        :disabled="runs.current_page === runs.last_page"
                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-50 hover:bg-muted"
                    >
                        Next
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
