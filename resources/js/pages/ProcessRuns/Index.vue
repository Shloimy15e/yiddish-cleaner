<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import { formatCreatedBy } from '@/lib/createdBy';
import { formatDateTime } from '@/lib/date';
import {
    getProcessRunStatusClass,
    getProcessRunStatusLabel,
} from '@/lib/processRunStatus';
import { type BreadcrumbItem } from '@/types';
import type { ProcessingRunListItem } from '@/types/process-runs';
import type { ColumnDef } from '@/components/ui/data-table/types';

const props = defineProps<{
    runs: {
        data: ProcessingRunListItem[];
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

const columns: ColumnDef<ProcessingRunListItem>[] = [
    { key: 'id', label: 'Run' },
    { key: 'source_type', label: 'Source' },
    { key: 'progress', label: 'Progress' },
    { key: 'status', label: 'Status' },
    { key: 'user', label: 'Created By' },
    { key: 'created_at', label: 'Started' },
    { key: 'actions', label: 'Actions' },
];

const progressPercent = (run: ProcessingRunListItem) => {
    if (!run.total) return 0;
    return Math.min(100, Math.round(((run.completed + run.failed) / run.total) * 100));
};

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

            <DataTable
                :columns="columns"
                :items="runs.data"
                item-key="id"
                empty-message="No import runs yet"
            >
                <template #cell-id="{ item }">
                    <div class="font-medium">#{{ item.id }}</div>
                    <div class="text-xs text-muted-foreground">
                        {{ item.completed + item.failed }} / {{ item.total || 0 }} processed
                    </div>
                </template>

                <template #cell-source_type="{ item }">
                    <div class="text-sm capitalize">{{ item.source_type || 'sheet' }}</div>
                    <div v-if="item.source_url" class="text-xs text-muted-foreground truncate max-w-65">
                        {{ item.source_url }}
                    </div>
                </template>

                <template #cell-progress="{ item }">
                    <div class="h-2 w-full rounded-full bg-muted">
                        <div
                            class="h-2 rounded-full bg-primary transition-all"
                            :style="{ width: `${progressPercent(item)}%` }"
                        ></div>
                    </div>
                    <div class="text-xs text-muted-foreground mt-1">{{ progressPercent(item) }}%</div>
                </template>

                <template #cell-status="{ item }">
                    <span :class="['inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium', getProcessRunStatusClass(item.status)]">
                        {{ getProcessRunStatusLabel(item.status) }}
                    </span>
                </template>

                <template #cell-user="{ item }">
                    <span class="text-sm text-muted-foreground">
                        {{ formatCreatedBy(item.user, undefined) }}
                    </span>
                </template>

                <template #cell-created_at="{ item }">
                    <span class="text-sm text-muted-foreground">
                        {{ formatDateTime(item.created_at) }}
                    </span>
                </template>

                <template #cell-actions="{ item }">
                    <Link :href="route('audio-samples.run', item.id)" class="text-sm text-primary hover:underline">
                        View
                    </Link>
                </template>
            </DataTable>

            <TablePagination
                :current-page="runs.current_page"
                :last-page="runs.last_page"
                :per-page="runs.per_page"
                :total="runs.total"
                noun="runs"
                @page-change="goToPage"
            />
        </div>
    </AppLayout>
</template>
