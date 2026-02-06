<script setup lang="ts">
import { ChartBarIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline';
import { Head, Link } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import { getWerColor } from '@/lib/asrMetrics';
import { type BreadcrumbItem } from '@/types';
import type { BenchmarkTranscription } from '@/types/transcriptions';
import type { ColumnDef } from '@/components/ui/data-table/types';

interface Stats {
    sample_count: number;
    avg_custom_wer: number | null;
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

const getSourceBadge = (source: string) => {
    if (source === 'generated') {
        return { label: 'ASR', class: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' };
    }
    return { label: 'Manual', class: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' };
};

const columns: ColumnDef<BenchmarkTranscription>[] = [
    { key: 'audio_sample.name', label: 'Sample' },
    { key: 'custom_wer', label: 'Custom WER' },
    { key: 'wer', label: 'WER' },
    { key: 'cer', label: 'CER' },
    { key: 'source', label: 'Source' },
    { key: 'notes', label: 'Notes', cellClass: 'text-sm text-muted-foreground max-w-xs truncate' },
    { key: 'created_at', label: 'Date', cellClass: 'text-sm text-muted-foreground' },
];
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
            <div class="grid gap-4 md:grid-cols-6">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Avg Custom WER</div>
                    <div v-if="stats.avg_custom_wer !== null" :class="['text-2xl font-bold', getWerColor(stats.avg_custom_wer, 'benchmark')]">{{ stats.avg_custom_wer }}%</div>
                    <div v-else class="text-2xl font-bold text-muted-foreground">&mdash;</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Average WER</div>
                    <div class="text-2xl font-bold text-muted-foreground">{{ stats.avg_wer }}%</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Average CER</div>
                    <div class="text-2xl font-bold text-muted-foreground">{{ stats.avg_cer }}%</div>
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
            <div>
                <div class="px-4 py-3 border-b bg-muted/50 rounded-t-xl border-x border-t">
                    <h2 class="font-semibold">Sample Results</h2>
                </div>
                <DataTable
                    :columns="columns"
                    :items="transcriptions.data"
                    item-key="id"
                    class="rounded-t-none border-t-0"
                    empty-message="No transcriptions found for this model."
                >
                    <template #cell-audio_sample.name="{ item }">
                        <Link :href="`/audio-samples/${item.audio_sample.id}`" class="font-medium text-primary hover:underline">
                            {{ item.audio_sample.name }}
                        </Link>
                    </template>

                    <template #cell-custom_wer="{ item }">
                        <span v-if="item.custom_wer !== null" :class="['font-bold', getWerColor(item.custom_wer, 'benchmark')]">
                            {{ item.custom_wer }}%
                        </span>
                        <span v-else class="text-muted-foreground">&mdash;</span>
                    </template>

                    <template #cell-wer="{ item }">
                        <span class="font-medium text-muted-foreground">{{ item.wer }}%</span>
                    </template>

                    <template #cell-cer="{ item }">
                        <span class="text-muted-foreground">{{ item.cer }}%</span>
                    </template>

                    <template #cell-source="{ item }">
                        <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', getSourceBadge(item.source).class]">
                            {{ getSourceBadge(item.source).label }}
                        </span>
                    </template>

                    <template #cell-notes="{ item }">
                        {{ item.notes || '-' }}
                    </template>

                    <template #cell-created_at="{ value }">
                        {{ value }}
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
