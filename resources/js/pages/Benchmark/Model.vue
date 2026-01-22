<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { BenchmarkTranscription } from '@/types/transcriptions';
import { ChartBarIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline';

interface Stats {
    sample_count: number;
    avg_wer: number;
    avg_cer: number;
    best_wer: number;
    worst_wer: number;
    stddev_wer: number;
    total_substitutions: number;
    total_insertions: number;
    total_deletions: number;
    total_words: number;
}

const props = defineProps<{
    modelName: string;
    transcriptions: { data: BenchmarkTranscription[] };
    stats: Stats;
    distribution: Record<number, number>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Benchmarks', href: '/benchmark' },
    { title: props.modelName, href: `/benchmark/models/${encodeURIComponent(props.modelName)}` },
];

const getWerColor = (wer: number) => {
    if (wer < 10) return 'text-green-600 dark:text-green-400';
    if (wer < 20) return 'text-yellow-600 dark:text-yellow-400';
    if (wer < 30) return 'text-orange-600 dark:text-orange-400';
    return 'text-red-600 dark:text-red-400';
};

const getSourceBadge = (source: string) => {
    if (source === 'generated') {
        return { label: 'ASR', class: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' };
    }
    return { label: 'Manual', class: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' };
};
</script>

<template>
    <Head :title="`${modelName} - Benchmark`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <Link href="/benchmark" class="text-sm text-muted-foreground hover:text-foreground flex items-center gap-1 mb-2">
                        <ArrowLeftIcon class="w-4 h-4" />
                        Back to Leaderboard
                    </Link>
                    <h1 class="text-2xl font-bold flex items-center gap-2">
                        <ChartBarIcon class="w-7 h-7 text-primary" />
                        {{ modelName }}
                    </h1>
                    <p class="text-muted-foreground mt-1">
                        Performance metrics across {{ stats.sample_count }} samples
                    </p>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-5">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Average WER</div>
                    <div :class="['text-2xl font-bold', getWerColor(stats.avg_wer)]">{{ stats.avg_wer }}%</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Average CER</div>
                    <div :class="['text-2xl font-bold', getWerColor(stats.avg_cer)]">{{ stats.avg_cer }}%</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Best WER</div>
                    <div class="text-2xl font-bold text-green-600">{{ stats.best_wer }}%</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Worst WER</div>
                    <div class="text-2xl font-bold text-red-600">{{ stats.worst_wer }}%</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Std Dev</div>
                    <div class="text-2xl font-bold">{{ stats.stddev_wer }}%</div>
                </div>
            </div>

            <!-- Error Breakdown -->
            <div class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold mb-3">Error Breakdown</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-sm text-muted-foreground">Total Words</div>
                        <div class="text-xl font-bold">{{ stats.total_words.toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-muted-foreground">Substitutions</div>
                        <div class="text-xl font-bold text-orange-600">{{ stats.total_substitutions.toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-muted-foreground">Insertions</div>
                        <div class="text-xl font-bold text-blue-600">{{ stats.total_insertions.toLocaleString() }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-muted-foreground">Deletions</div>
                        <div class="text-xl font-bold text-red-600">{{ stats.total_deletions.toLocaleString() }}</div>
                    </div>
                </div>
            </div>

            <!-- Transcriptions List -->
            <div class="rounded-xl border bg-card overflow-hidden">
                <div class="px-4 py-3 border-b bg-muted/50">
                    <h2 class="font-semibold">Sample Results</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-muted/30">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-medium">Sample</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">WER</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">CER</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Source</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Notes</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="t in transcriptions.data" :key="t.id" class="hover:bg-muted/30">
                                <td class="px-4 py-3">
                                    <Link :href="`/audio-samples/${t.audio_sample.id}`" class="font-medium text-primary hover:underline">
                                        {{ t.audio_sample.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['font-bold', getWerColor(t.wer)]">{{ t.wer }}%</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['font-medium', getWerColor(t.cer)]">{{ t.cer }}%</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', getSourceBadge(t.source).class]">
                                        {{ getSourceBadge(t.source).label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-muted-foreground max-w-xs truncate">
                                    {{ t.notes || '-' }}
                                </td>
                                <td class="px-4 py-3 text-sm text-muted-foreground">{{ t.created_at }}</td>
                            </tr>
                            <tr v-if="transcriptions.data.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                    No transcriptions found for this model.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
