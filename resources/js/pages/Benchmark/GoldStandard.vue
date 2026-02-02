<script setup lang="ts">
import { StarIcon, ArrowUpIcon, ArrowDownIcon, TrophyIcon } from '@heroicons/vue/24/outline';
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import { getWerColor } from '@/lib/asrMetrics';
import { type BreadcrumbItem } from '@/types';

interface ModelStats {
    rank: number;
    model_name: string;
    sample_count: number;
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
    best_wer: number | null;
    best_model: string | null;
}

interface Stats {
    benchmark_samples: number;
    total_transcriptions: number;
    total_models: number;
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
            <div class="grid gap-4 md:grid-cols-4">
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
                    <div class="text-sm text-muted-foreground">Average WER</div>
                    <div :class="['text-2xl font-bold', getWerColor(stats.avg_wer, 'benchmark')]">{{ stats.avg_wer }}%</div>
                </div>
            </div>

            <!-- Benchmark Samples -->
            <div class="rounded-xl border bg-card overflow-hidden">
                <div class="border-b bg-muted/50 px-4 py-3">
                    <h2 class="font-semibold flex items-center gap-2">
                        <StarIcon class="w-5 h-5 text-amber-500" />
                        Benchmark Samples
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-muted/30">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-medium">Sample</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Status</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Base Transcription</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">ASR Results</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Best WER</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Best Model</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="sample in samples" :key="sample.id" class="hover:bg-muted/30">
                                <td class="px-4 py-3">
                                    <Link :href="`/audio-samples/${sample.id}`" class="font-medium text-primary hover:underline">
                                        {{ sample.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['inline-flex rounded-full px-2 py-1 text-xs font-medium', getStatusBadge(sample.status)]">
                                        {{ sample.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span v-if="sample.has_base" class="text-green-600">✓ Linked</span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3">{{ sample.asr_count }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="sample.best_wer !== null" :class="['font-bold', getWerColor(sample.best_wer, 'benchmark')]">
                                        {{ sample.best_wer }}%
                                    </span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span v-if="sample.best_model">{{ sample.best_model }}</span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                            </tr>
                            <tr v-if="samples.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                    No benchmark samples configured. Mark audio samples as "benchmark" to track them here.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Model Leaderboard -->
            <div class="rounded-xl border bg-card overflow-hidden">
                <div class="border-b bg-muted/50 px-4 py-3">
                    <h2 class="font-semibold flex items-center gap-2">
                        <TrophyIcon class="w-5 h-5 text-amber-500" />
                        Model Performance (Gold Standard Only)
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-muted/30">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-medium">Rank</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Model</th>
                                <th class="px-4 py-3 text-left text-sm font-medium cursor-pointer hover:text-primary" @click="sortBy('wer')">
                                    <span class="flex items-center gap-1">
                                        Avg WER
                                        <ArrowUpIcon v-if="sort === 'wer' && dir === 'asc'" class="w-4 h-4" />
                                        <ArrowDownIcon v-if="sort === 'wer' && dir === 'desc'" class="w-4 h-4" />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-left text-sm font-medium cursor-pointer hover:text-primary" @click="sortBy('cer')">
                                    <span class="flex items-center gap-1">
                                        Avg CER
                                        <ArrowUpIcon v-if="sort === 'cer' && dir === 'asc'" class="w-4 h-4" />
                                        <ArrowDownIcon v-if="sort === 'cer' && dir === 'desc'" class="w-4 h-4" />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Best / Worst WER</th>
                                <th class="px-4 py-3 text-left text-sm font-medium cursor-pointer hover:text-primary" @click="sortBy('count')">
                                    <span class="flex items-center gap-1">
                                        Samples
                                        <ArrowUpIcon v-if="sort === 'count' && dir === 'asc'" class="w-4 h-4" />
                                        <ArrowDownIcon v-if="sort === 'count' && dir === 'desc'" class="w-4 h-4" />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-left text-sm font-medium">S / I / D</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="model in models" :key="model.model_name" class="hover:bg-muted/30">
                                <td class="px-4 py-3 text-lg font-bold">{{ getRankBadge(model.rank) }}</td>
                                <td class="px-4 py-3">
                                    <Link :href="`/benchmark/models/${encodeURIComponent(model.model_name)}`" class="font-medium text-primary hover:underline">
                                        {{ model.model_name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['font-bold', getWerColor(model.avg_wer, 'benchmark')]">{{ model.avg_wer }}%</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['font-medium', getWerColor(model.avg_cer, 'benchmark')]">{{ model.avg_cer }}%</span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="text-green-600">{{ model.best_wer }}%</span>
                                    <span class="text-muted-foreground mx-1">/</span>
                                    <span class="text-red-600">{{ model.worst_wer }}%</span>
                                </td>
                                <td class="px-4 py-3">{{ model.sample_count }}</td>
                                <td class="px-4 py-3 text-sm text-muted-foreground">
                                    {{ model.avg_substitutions }} / {{ model.avg_insertions }} / {{ model.avg_deletions }}
                                </td>
                            </tr>
                            <tr v-if="models.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                                    No benchmark results yet for gold standard samples.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Legend -->
            <div class="text-sm text-muted-foreground">
                <strong>S / I / D</strong> = Average Substitutions / Insertions / Deletions per sample
            </div>
        </div>
    </AppLayout>
</template>
