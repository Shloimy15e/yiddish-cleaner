<script setup lang="ts">
import { TrophyIcon, ArrowsRightLeftIcon, StarIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import { getWerColor } from '@/lib/asrMetrics';
import { type BreadcrumbItem } from '@/types';
import type { ColumnDef } from '@/components/ui/data-table/types';

interface ModelStats {
    rank: number;
    model_name: string;
    sample_count: number;
    avg_custom_wer: number | null;
    avg_wer: number;
    avg_cer: number;
    best_wer: number;
    worst_wer: number;
    avg_substitutions: number;
    avg_insertions: number;
    avg_deletions: number;
}

interface Stats {
    total_transcriptions: number;
    total_models: number;
    avg_custom_wer: number;
    avg_wer: number;
    avg_cer: number;
}

const props = defineProps<{
    models: ModelStats[];
    stats: Stats;
    sort: string;
    dir: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Benchmarks', href: '/benchmark' },
];

const sortBy = (column: string) => {
    const newDir = props.sort === column && props.dir === 'asc' ? 'desc' : 'asc';
    router.get('/benchmark', { sort: column, dir: newDir }, { preserveState: true });
};

const getRankBadge = (rank: number) => {
    if (rank === 1) return '🥇';
    if (rank === 2) return '🥈';
    if (rank === 3) return '🥉';
    return `#${rank}`;
};

const columns: ColumnDef<ModelStats>[] = [
    { key: 'rank', label: 'Rank' },
    { key: 'model_name', label: 'Model' },
    { key: 'custom_wer', label: 'Avg Custom WER', sortable: true },
    { key: 'wer', label: 'Avg WER', sortable: true },
    { key: 'cer', label: 'Avg CER', sortable: true },
    { key: 'best_worst_wer', label: 'Best / Worst WER' },
    { key: 'count', label: 'Samples', sortable: true },
    { key: 'sid', label: 'S / I / D' },
];
</script>

<template>
    <Head title="Benchmark Leaderboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <!-- Header -->
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <TrophyIcon class="w-7 h-7 text-amber-500" />
                        ASR Benchmark Leaderboard
                    </h1>
                    <p class="text-muted-foreground mt-1">
                        Compare ASR model performance across {{ stats.total_transcriptions }} transcriptions
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link
                        href="/benchmark/gold-standard"
                        class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 font-medium hover:bg-muted"
                    >
                        <StarIcon class="w-4 h-4 text-amber-500" />
                        Gold Standard
                    </Link>
                    <Link
                        href="/benchmark/compare"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        <ArrowsRightLeftIcon class="w-4 h-4" />
                        Compare Models
                    </Link>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-5">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Total Transcriptions</div>
                    <div class="text-2xl font-bold">{{ stats.total_transcriptions }}</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Models Tested</div>
                    <div class="text-2xl font-bold">{{ stats.total_models }}</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Avg Custom WER</div>
                    <div :class="['text-2xl font-bold', getWerColor(stats.avg_custom_wer, 'benchmark')]">{{ stats.avg_custom_wer }}%</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Average WER</div>
                    <div class="text-2xl font-bold text-muted-foreground">{{ stats.avg_wer }}%</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Average CER</div>
                    <div class="text-2xl font-bold text-muted-foreground">{{ stats.avg_cer }}%</div>
                </div>
            </div>

            <!-- Leaderboard Table -->
            <DataTable
                :columns="columns"
                :items="models"
                item-key="model_name"
                :sort-key="sort"
                :sort-direction="dir as 'asc' | 'desc'"
                empty-message="No benchmark results yet. Transcribe some audio samples to see results here."
                @sort="sortBy"
            >
                <template #cell-rank="{ item }">
                    <span class="text-lg font-bold">{{ getRankBadge(item.rank) }}</span>
                </template>

                <template #cell-model_name="{ item }">
                    <Link :href="`/benchmark/models/${encodeURIComponent(item.model_name)}`" class="font-medium text-primary hover:underline">
                        {{ item.model_name }}
                    </Link>
                </template>

                <template #cell-custom_wer="{ item }">
                    <span v-if="item.avg_custom_wer !== null" :class="['font-bold', getWerColor(item.avg_custom_wer, 'benchmark')]">
                        {{ item.avg_custom_wer }}%
                    </span>
                    <span v-else class="text-muted-foreground">&mdash;</span>
                </template>

                <template #cell-wer="{ item }">
                    <span class="font-medium text-muted-foreground">{{ item.avg_wer }}%</span>
                </template>

                <template #cell-cer="{ item }">
                    <span class="text-muted-foreground">{{ item.avg_cer }}%</span>
                </template>

                <template #cell-best_worst_wer="{ item }">
                    <span class="text-sm">
                        <span class="text-green-600">{{ item.best_wer }}%</span>
                        <span class="text-muted-foreground mx-1">/</span>
                        <span class="text-red-600">{{ item.worst_wer }}%</span>
                    </span>
                </template>

                <template #cell-count="{ item }">
                    {{ item.sample_count }}
                </template>

                <template #cell-sid="{ item }">
                    <span class="text-sm text-muted-foreground">
                        {{ item.avg_substitutions }} / {{ item.avg_insertions }} / {{ item.avg_deletions }}
                    </span>
                </template>
            </DataTable>

            <!-- Legend -->
            <div class="text-sm text-muted-foreground">
                <strong>S / I / D</strong> = Average Substitutions / Insertions / Deletions per sample
            </div>
        </div>
    </AppLayout>
</template>
