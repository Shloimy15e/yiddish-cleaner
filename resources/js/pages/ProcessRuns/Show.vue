<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface AudioSample {
    id: number;
    name: string;
    status: string;
    clean_rate: number | null;
    error_message: string | null;
    created_at: string;
}

interface ProcessingRun {
    id: number;
    preset: string | null;
    mode: string | null;
    source_type: string | null;
    source_url: string | null;
    status: string;
    total: number;
    completed: number;
    failed: number;
    error_message: string | null;
    created_at: string;
    options: Record<string, unknown> | null;
    audio_samples: AudioSample[];
}

const props = defineProps<{
    run: ProcessingRun;
}>();

const run = ref({
    ...props.run,
    audio_samples: [...(props.run.audio_samples || [])],
});
const samples = ref<AudioSample[]>([...(props.run.audio_samples || [])]);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Import Runs', href: route('audio-samples.runs') },
    { title: 'Import Run', href: route('audio-samples.run', props.run.id) },
];

const progressPercent = computed(() => {
    if (!run.value.total) return 0;
    return Math.min(100, Math.round(((run.value.completed + run.value.failed) / run.value.total) * 100));
});

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        pending_transcript: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        imported: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        cleaning: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        cleaned: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        validated: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    };
    return colors[status] ?? 'bg-muted text-muted-foreground';
};

const getStatusLabel = (status: string) => {
    const labels: Record<string, string> = {
        pending: 'Pending',
        processing: 'Processing',
        completed: 'Completed',
        completed_with_errors: 'Completed (Errors)',
        failed: 'Failed',
        pending_transcript: 'Needs Transcript',
        imported: 'Needs Cleaning',
        cleaning: 'Cleaning...',
        cleaned: 'Ready for Review',
        validated: 'Benchmark Ready',
    };
    return labels[status] ?? status;
};

const formatDate = (value: string) => new Date(value).toLocaleString();

const updateSampleFromEvent = (payload: {
    audio_sample_id: number;
    audio_sample_name: string;
    status: string;
    clean_rate: number | null;
    completed: number;
    failed: number;
    total: number;
}) => {
    run.value.completed = payload.completed;
    run.value.failed = payload.failed;
    run.value.total = payload.total;

    const existing = samples.value.find((s) => s.id === payload.audio_sample_id);
    if (existing) {
        existing.status = payload.status;
        existing.clean_rate = payload.clean_rate;
        return;
    }

    samples.value.unshift({
        id: payload.audio_sample_id,
        name: payload.audio_sample_name,
        status: payload.status,
        clean_rate: payload.clean_rate,
        error_message: null,
        created_at: new Date().toISOString(),
    });
};

const updateRunFromEvent = (payload: {
    status: string;
    completed: number;
    failed: number;
    total: number;
}) => {
    run.value.status = payload.status;
    run.value.completed = payload.completed;
    run.value.failed = payload.failed;
    run.value.total = payload.total;
};

let channel: any = null;

onMounted(() => {
    const echo = (window as any)?.Echo;
    if (!echo) return;

    channel = echo.private(`runs.${props.run.id}`)
        .listen('AudioSampleProcessed', (payload: any) => updateSampleFromEvent(payload))
        .listen('BatchCompleted', (payload: any) => updateRunFromEvent(payload));
});

onBeforeUnmount(() => {
    const echo = (window as any)?.Echo;
    if (channel?.stopListening) {
        channel.stopListening('AudioSampleProcessed');
        channel.stopListening('BatchCompleted');
    }
    if (echo?.leave) {
        echo.leave(`runs.${props.run.id}`);
    }
});
</script>

<template>
    <Head title="Import Run" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Import Run</h1>
                    <p class="text-sm text-muted-foreground">
                        Track progress for this spreadsheet import.
                    </p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <Link
                        :href="route('audio-samples.create')"
                        class="w-full rounded-lg border px-4 py-2 text-center text-sm font-medium hover:bg-accent transition-colors sm:w-auto"
                    >
                        Start another import
                    </Link>
                    <Link
                        :href="route('audio-samples.index')"
                        class="w-full rounded-lg bg-primary px-4 py-2 text-center text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors sm:w-auto"
                    >
                        View all samples
                    </Link>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="rounded-xl border bg-card p-6 lg:col-span-2">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-lg font-semibold">Progress</h2>
                            <p class="text-sm text-muted-foreground">
                                {{ run.completed + run.failed }} / {{ run.total || 0 }} processed
                            </p>
                        </div>
                        <span :class="['rounded-full px-3 py-1 text-xs font-medium', getStatusColor(run.status)]">
                            {{ getStatusLabel(run.status) }}
                        </span>
                    </div>

                    <div class="h-2 w-full rounded-full bg-muted">
                        <div
                            class="h-2 rounded-full bg-primary transition-all"
                            :style="{ width: `${progressPercent}%` }"
                        ></div>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-lg border bg-muted/30 p-4">
                            <div class="text-xs text-muted-foreground">Completed</div>
                            <div class="text-xl font-semibold">{{ run.completed }}</div>
                        </div>
                        <div class="rounded-lg border bg-muted/30 p-4">
                            <div class="text-xs text-muted-foreground">Failed</div>
                            <div class="text-xl font-semibold">{{ run.failed }}</div>
                        </div>
                        <div class="rounded-lg border bg-muted/30 p-4">
                            <div class="text-xs text-muted-foreground">Total</div>
                            <div class="text-xl font-semibold">{{ run.total || 0 }}</div>
                        </div>
                    </div>

                    <div v-if="run.error_message" class="mt-4 rounded-lg border border-destructive/30 bg-destructive/10 p-4">
                        <p class="text-sm text-destructive">{{ run.error_message }}</p>
                    </div>
                </div>

                <div class="rounded-xl border bg-card p-6">
                    <h2 class="text-lg font-semibold mb-4">Run Details</h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground">Run ID</span>
                            <span class="font-medium">#{{ run.id }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground">Source</span>
                            <span class="font-medium capitalize">{{ run.source_type || 'sheet' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground">Mode</span>
                            <span class="font-medium">{{ run.mode || 'import' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground">Started</span>
                            <span class="font-medium">{{ formatDate(run.created_at) }}</span>
                        </div>
                        <div v-if="run.source_url" class="pt-2">
                            <div class="text-muted-foreground">Source URL</div>
                            <div class="truncate text-xs mt-1">{{ run.source_url }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border bg-card">
                <div class="flex items-center justify-between border-b p-4">
                    <h2 class="font-semibold">Imported Samples</h2>
                    <span class="text-sm text-muted-foreground">{{ samples.length }} samples</span>
                </div>
                <div class="divide-y">
                    <div
                        v-for="sample in samples"
                        :key="sample.id"
                        class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="route('audio-samples.show', sample.id)"
                                class="font-medium hover:text-primary transition-colors truncate block"
                            >
                                {{ sample.name }}
                            </Link>
                            <div class="text-xs text-muted-foreground">
                                {{ formatDate(sample.created_at) }}
                            </div>
                            <p v-if="sample.error_message" class="text-xs text-destructive mt-1">
                                {{ sample.error_message }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span v-if="sample.clean_rate !== null" class="text-xs text-muted-foreground">
                                {{ sample.clean_rate }}%
                            </span>
                            <span :class="['rounded-full px-2 py-1 text-xs font-medium', getStatusColor(sample.status)]">
                                {{ getStatusLabel(sample.status) }}
                            </span>
                        </div>
                    </div>
                    <div v-if="samples.length === 0" class="p-6 text-center text-muted-foreground">
                        No samples have been imported yet.
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
