<script setup lang="ts">
import { StarIcon, TrophyIcon } from '@heroicons/vue/24/outline';
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

interface BenchmarkSample {
    id: number;
    name: string;
    status: string;
    has_base: boolean;
    asr_count: number;
    best_custom_wer: number | null;
    best_wer: number | null;
    best_model: string | null;
}

interface Stats {
    benchmark_samples: number;
    total_transcriptions: number;
    total_models: number;
    avg_custom_wer: number;
    avg_wer: number;
}

const props = defineProps<{
    models: ModelStats[];
    samples: BenchmarkSample[];
    stats: Stats;
    sort: string;
    dir: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Benchmarks', href: '/benchmark' },
    { title: 'Gold Standard', href: '/benchmark/gold-standard' },
];

const sortBy = (column: string) => {
    const newDir = props.sort === column && props.dir === 'asc' ? 'desc' : 'asc';
    router.get('/benchmark/gold-standard', { sort: column, dir: newDir }, { preserveState: true });
};

const getRankBadge = (rank: number) => {
    if (rank === 1) return '🥇';
    if (rank === 2) return '🥈';
    if (rank === 3) return '🥉';
    return `#${rank}`;
};

const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        'draft': 'bg-gray-100 text-gray-700',
        'pending_base': 'bg-yellow-100 text-yellow-700',
        'unclean': 'bg-orange-100 text-orange-700',
        'ready': 'bg-blue-100 text-blue-700',
        'benchmarked': 'bg-green-100 text-green-700',
    };
    return badges[status] || 'bg-gray-100 text-gray-700';
};

const sampleColumns: ColumnDef<BenchmarkSample>[] = [
    { key: 'name', label: 'Sample' },
    { key: 'status', label: 'Status' },
    { key: 'has_base', label: 'Base Transcription' },
    { key: 'asr_count', label: 'ASR Results' },
    { key: 'best_custom_wer', label: 'Best Custom WER' },
    { key: 'best_wer', label: 'Best WER' },
    { key: 'best_model', label: 'Best Model' },
];

const leaderboardColumns: ColumnDef<ModelStats>[] = [
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
    <Head title="Gold Standard Benchmark" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <!-- Header -->
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <StarIcon class="w-7 h-7 text-amber-500" />
                        Gold Standard Benchmark
                    </h1>
                    <p class="text-muted-foreground mt-1">
                        Track progress on {{ stats.benchmark_samples }} curated benchmark samples
                    </p>
                </div>
                <Link
                    href="/benchmark"
                    class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 font-medium hover:bg-muted"
                >
                    <TrophyIcon class="w-4 h-4" />
                    Full Leaderboard
                </Link>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-5">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Benchmark Samples</div>
                    <div class="text-2xl font-bold">{{ stats.benchmark_samples }}</div>
                </div>
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
            </div>

            <!-- Benchmark Samples -->
            <div>
                <div class="border-b bg-muted/50 px-4 py-3 rounded-t-xl border-x border-t">
                    <h2 class="font-semibold flex items-center gap-2">
                        <StarIcon class="w-5 h-5 text-amber-500" />
                        Benchmark Samples
                    </h2>
                </div>
                <DataTable
                    :columns="sampleColumns"
                    :items="samples"
                    item-key="id"
                    class="rounded-t-none border-t-0"
                    empty-message="No benchmark samples configured. Mark audio samples as &quot;benchmark&quot; to track them here."
                >
                    <template #cell-name="{ item }">
                        <Link :href="`/audio-samples/${item.id}`" class="font-medium text-primary hover:underline">
                            {{ item.name }}
                        </Link>
                    </template>

                    <template #cell-status="{ item }">
                        <span :class="['inline-flex rounded-full px-2 py-1 text-xs font-medium', getStatusBadge(item.status)]">
                            {{ item.status }}
                        </span>
                    </template>

                    <template #cell-has_base="{ item }">
                        <span v-if="item.has_base" class="text-green-600">&#10003; Linked</span>
                        <span v-else class="text-muted-foreground">&mdash;</span>
                    </template>

                    <template #cell-asr_count="{ value }">
                        {{ value }}
                    </template>

                    <template #cell-best_custom_wer="{ item }">
                        <span v-if="item.best_custom_wer !== null" :class="['font-bold', getWerColor(item.best_custom_wer, 'benchmark')]">
                            {{ item.best_custom_wer }}%
                        </span>
                        <span v-else class="text-muted-foreground">&mdash;</span>
                    </template>

                    <template #cell-best_wer="{ item }">
                        <span v-if="item.best_wer !== null" class="text-muted-foreground">
                            {{ item.best_wer }}%
                        </span>
                        <span v-else class="text-muted-foreground">&mdash;</span>
                    </template>

                    <template #cell-best_model="{ item }">
                        <span v-if="item.best_model" class="text-sm">{{ item.best_model }}</span>
                        <span v-else class="text-muted-foreground">&mdash;</span>
                    </template>
                </DataTable>
            </div>

            <!-- Model Leaderboard -->
            <div>
                <div class="border-b bg-muted/50 px-4 py-3 rounded-t-xl border-x border-t">
                    <h2 class="font-semibold flex items-center gap-2">
                        <TrophyIcon class="w-5 h-5 text-amber-500" />
                        Model Performance (Gold Standard Only)
                    </h2>
                </div>
                <DataTable
                    :columns="leaderboardColumns"
                    :items="models"
                    item-key="model_name"
                    :sort-key="sort"
                    :sort-direction="dir as 'asc' | 'desc'"
                    class="rounded-t-none border-t-0"
                    empty-message="No benchmark results yet for gold standard samples."
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
            </div>

            <!-- Legend -->
            <div class="text-sm text-muted-foreground">
                <strong>S / I / D</strong> = Average Substitutions / Insertions / Deletions per sample
            </div>
        </div>
    </AppLayout>
</template>
