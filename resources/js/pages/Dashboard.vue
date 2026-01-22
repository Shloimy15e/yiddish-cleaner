<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { AudioSampleSummary } from '@/types/audio-samples';

interface Stats {
    total_audio_samples: number;
    audio_samples_this_week: number;
    pending_base: number;
    unclean: number;
    ready: number;
    benchmarked: number;
}

interface Run {
    id: number;
    batch_id: string;
    preset: string;
    status: string;
    total: number;
    completed: number;
    failed: number;
}

const props = defineProps<{
    stats: Stats;
    recentAudioSamples: AudioSampleSummary[];
    activeRuns: Run[];
    pendingBaseQueue: AudioSampleSummary[];
    uncleanQueue: AudioSampleSummary[];
    readyQueue: AudioSampleSummary[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
];

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        'draft': 'bg-gray-500/20 text-gray-400',
        'pending_base': 'bg-amber-500/20 text-amber-400',
        'unclean': 'bg-blue-500/20 text-blue-400',
        'ready': 'bg-green-500/20 text-green-400',
        'benchmarked': 'bg-purple-500/20 text-purple-400',
    };
    return colors[status] ?? 'bg-muted text-muted-foreground';
};

const getStatusLabel = (status: string) => {
    const labels: Record<string, string> = {
        'draft': 'Draft',
        'pending_base': 'Needs Transcript',
        'unclean': 'Needs Validation',
        'ready': 'Ready',
        'benchmarked': 'Benchmarked',
    };
    return labels[status] ?? status;
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <!-- Workflow Progress Stats -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                <div class="rounded-xl border bg-card p-6 hover:border-primary/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">Total Samples</div>
                    <div class="text-3xl font-bold gradient-text">{{ stats.total_audio_samples }}</div>
                </div>
                <div class="rounded-xl border bg-card p-6 hover:border-primary/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">This Week</div>
                    <div class="text-3xl font-bold text-primary">{{ stats.audio_samples_this_week }}</div>
                </div>
                <div class="rounded-xl border bg-card p-6 hover:border-amber-500/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">Pending Transcript</div>
                    <div class="text-3xl font-bold text-amber-400">{{ stats.pending_base }}</div>
                </div>
                <div class="rounded-xl border bg-card p-6 hover:border-blue-500/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">Needs Validation</div>
                    <div class="text-3xl font-bold text-blue-400">{{ stats.unclean }}</div>
                </div>
                <div class="rounded-xl border bg-card p-6 hover:border-green-500/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">Ready</div>
                    <div class="text-3xl font-bold text-green-400">{{ stats.ready }}</div>
                </div>
                <div class="rounded-xl border bg-card p-6 hover:border-purple-500/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">Benchmarked</div>
                    <div class="text-3xl font-bold text-purple-400">{{ stats.benchmarked }}</div>
                </div>
            </div>

            <!-- Workflow Queues -->
            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Pending Base Queue -->
                <div class="rounded-xl border bg-card hover:shadow-glow-sm transition-all">
                    <div class="flex items-center justify-between border-b p-4">
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-2 rounded-full bg-amber-400"></div>
                            <h2 class="font-semibold">Needs Transcript</h2>
                        </div>
                        <Link href="/audio-samples?status=pending_base" class="text-sm text-primary hover:text-primary/80 transition-colors">View all</Link>
                    </div>
                    <div class="divide-y divide-border">
                        <div v-for="sample in pendingBaseQueue" :key="sample.id" class="flex items-center justify-between p-4 hover:bg-muted/30 transition-colors">
                            <div class="min-w-0 flex-1">
                                <Link :href="`/audio-samples/${sample.id}`" class="font-medium hover:text-primary transition-colors truncate block">
                                    {{ sample.name }}
                                </Link>
                                <div class="text-sm text-muted-foreground">{{ sample.created_at }}</div>
                            </div>
                            <span :class="['rounded-full px-2 py-1 text-xs font-medium shrink-0 ml-2', getStatusColor(sample.status)]">
                                {{ getStatusLabel(sample.status) }}
                            </span>
                        </div>
                        <div v-if="pendingBaseQueue.length === 0" class="p-4 text-center text-muted-foreground">
                            No samples need transcripts
                        </div>
                    </div>
                </div>

                <!-- Unclean Queue -->
                <div class="rounded-xl border bg-card hover:shadow-glow-sm transition-all">
                    <div class="flex items-center justify-between border-b p-4">
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-2 rounded-full bg-blue-400"></div>
                            <h2 class="font-semibold">Needs Validation</h2>
                        </div>
                        <Link href="/audio-samples?status=unclean" class="text-sm text-primary hover:text-primary/80 transition-colors">View all</Link>
                    </div>
                    <div class="divide-y divide-border">
                        <div v-for="sample in uncleanQueue" :key="sample.id" class="flex items-center justify-between p-4 hover:bg-muted/30 transition-colors">
                            <div class="min-w-0 flex-1">
                                <Link :href="`/audio-samples/${sample.id}`" class="font-medium hover:text-primary transition-colors truncate block">
                                    {{ sample.name }}
                                </Link>
                            </div>
                            <span :class="['rounded-full px-2 py-1 text-xs font-medium shrink-0 ml-2', getStatusColor(sample.status)]">
                                {{ getStatusLabel(sample.status) }}
                            </span>
                        </div>
                        <div v-if="uncleanQueue.length === 0" class="p-4 text-center text-muted-foreground">
                            No samples need validation
                        </div>
                    </div>
                </div>

                <!-- Ready Queue -->
                <div class="rounded-xl border bg-card hover:shadow-glow-sm transition-all">
                    <div class="flex items-center justify-between border-b p-4">
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-2 rounded-full bg-green-400"></div>
                            <h2 class="font-semibold">Ready for Benchmark</h2>
                        </div>
                        <Link href="/audio-samples?status=ready" class="text-sm text-primary hover:text-primary/80 transition-colors">View all</Link>
                    </div>
                    <div class="divide-y divide-border">
                        <div v-for="sample in readyQueue" :key="sample.id" class="flex items-center justify-between p-4 hover:bg-muted/30 transition-colors">
                            <div class="min-w-0 flex-1">
                                <Link :href="`/audio-samples/${sample.id}`" class="font-medium hover:text-primary transition-colors truncate block">
                                    {{ sample.name }}
                                </Link>
                            </div>
                            <span :class="['rounded-full px-2 py-1 text-xs font-medium shrink-0 ml-2', getStatusColor(sample.status)]">
                                {{ getStatusLabel(sample.status) }}
                            </span>
                        </div>
                        <div v-if="readyQueue.length === 0" class="p-4 text-center text-muted-foreground">
                            No samples ready for benchmark
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Recent Audio Samples -->
                <div class="rounded-xl border bg-card hover:shadow-glow-sm transition-all">
                    <div class="flex items-center justify-between border-b p-4">
                        <h2 class="font-semibold">Recent Imports</h2>
                        <Link href="/audio-samples" class="text-sm text-primary hover:text-primary/80 transition-colors">View all</Link>
                    </div>
                    <div class="divide-y divide-border">
                        <div v-for="sample in recentAudioSamples" :key="sample.id" class="flex items-center justify-between p-4 hover:bg-muted/30 transition-colors">
                            <div class="min-w-0 flex-1">
                                <Link :href="`/audio-samples/${sample.id}`" class="font-medium hover:text-primary transition-colors truncate block">
                                    {{ sample.name }}
                                </Link>
                                <div class="text-sm text-muted-foreground">{{ sample.created_at }}</div>
                            </div>
                            <span :class="['rounded-full px-2 py-1 text-xs font-medium shrink-0 ml-2', getStatusColor(sample.status)]">
                                {{ getStatusLabel(sample.status) }}
                            </span>
                        </div>
                        <div v-if="recentAudioSamples.length === 0" class="p-4 text-center text-muted-foreground">
                            No audio samples yet
                        </div>
                    </div>
                </div>

                <!-- Active Import Runs -->
                <div class="rounded-xl border bg-card">
                    <div class="flex items-center justify-between border-b p-4">
                        <h2 class="font-semibold">Active Import Runs</h2>
                        <Link :href="route('audio-samples.runs')" class="text-sm text-primary hover:text-primary/80 transition-colors">View all</Link>
                    </div>
                    <div class="divide-y">
                        <div v-for="run in activeRuns" :key="run.id" class="p-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-medium">{{ run.preset }}</span>
                                <span class="text-sm text-muted-foreground">
                                    {{ run.completed + run.failed }} / {{ run.total }}
                                </span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                <div 
                                    class="h-2 rounded-full bg-primary transition-all" 
                                    :style="{ width: `${((run.completed + run.failed) / run.total) * 100}%` }"
                                ></div>
                            </div>
                        </div>
                        <div v-if="activeRuns.length === 0" class="p-4 text-center text-muted-foreground">
                            No active imports
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <Link href="/imports/create" class="w-full rounded-lg bg-primary px-6 py-3 text-center font-medium text-primary-foreground hover:bg-primary/90 transition-colors sm:w-auto">
                    Import Audio Samples
                </Link>
    <!--             <Link href="/training/create" class="w-full rounded-lg border px-6 py-3 text-center font-medium hover:bg-accent transition-colors sm:w-auto">
                    Create Training Version
                </Link> -->
            </div>
        </div>
    </AppLayout>
</template>
