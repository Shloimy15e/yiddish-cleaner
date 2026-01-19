<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface Stats {
    total_documents: number;
    documents_this_week: number;
    pending_validation: number;
    average_clean_rate: number;
}

interface Document {
    id: number;
    name: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    status: string;
    created_at: string;
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
    recentDocuments: Document[];
    activeRuns: Run[];
    validationQueue: Document[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
];

const getCategoryColor = (category: string | null) => {
    const colors: Record<string, string> = {
        excellent: 'clean-rate-excellent',
        good: 'clean-rate-good',
        moderate: 'clean-rate-moderate',
        low: 'clean-rate-low',
        poor: 'clean-rate-poor',
    };
    return colors[category ?? ''] ?? 'bg-muted text-muted-foreground';
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded-xl border bg-card p-6 hover:border-primary/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">Total Documents</div>
                    <div class="text-3xl font-bold gradient-text">{{ stats.total_documents }}</div>
                </div>
                <div class="rounded-xl border bg-card p-6 hover:border-primary/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">This Week</div>
                    <div class="text-3xl font-bold text-primary">{{ stats.documents_this_week }}</div>
                </div>
                <div class="rounded-xl border bg-card p-6 hover:border-primary/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">Pending Validation</div>
                    <div class="text-3xl font-bold text-secondary">{{ stats.pending_validation }}</div>
                </div>
                <div class="rounded-xl border bg-card p-6 hover:border-primary/50 transition-colors">
                    <div class="text-sm font-medium text-muted-foreground">Avg Clean Rate</div>
                    <div class="text-3xl font-bold text-teal-400">{{ Math.round(stats.average_clean_rate) }}%</div>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Recent Documents -->
                <div class="rounded-xl border bg-card hover:shadow-glow-sm transition-all">
                    <div class="flex items-center justify-between border-b p-4">
                        <h2 class="font-semibold">Recent Documents</h2>
                        <Link href="/documents" class="text-sm text-primary hover:text-primary/80 transition-colors">View all</Link>
                    </div>
                    <div class="divide-y divide-border">
                        <div v-for="doc in recentDocuments" :key="doc.id" class="flex items-center justify-between p-4 hover:bg-muted/30 transition-colors">
                            <div>
                                <Link :href="`/documents/${doc.id}`" class="font-medium hover:text-primary transition-colors">
                                    {{ doc.name }}
                                </Link>
                                <div class="text-sm text-muted-foreground">{{ doc.created_at }}</div>
                            </div>
                            <span v-if="doc.clean_rate" :class="['rounded-full px-2 py-1 text-xs font-medium', getCategoryColor(doc.clean_rate_category)]">
                                {{ doc.clean_rate }}%
                            </span>
                        </div>
                        <div v-if="recentDocuments.length === 0" class="p-4 text-center text-muted-foreground">
                            No documents yet
                        </div>
                    </div>
                </div>

                <!-- Validation Queue -->
                <div class="rounded-xl border bg-card">
                    <div class="flex items-center justify-between border-b p-4">
                        <h2 class="font-semibold">Validation Queue</h2>
                        <Link href="/documents?validated=no" class="text-sm text-primary hover:underline">View all</Link>
                    </div>
                    <div class="divide-y">
                        <div v-for="doc in validationQueue" :key="doc.id" class="flex items-center justify-between p-4">
                            <div>
                                <Link :href="`/documents/${doc.id}`" class="font-medium hover:underline">
                                    {{ doc.name }}
                                </Link>
                            </div>
                            <span :class="['rounded-full px-2 py-1 text-xs font-medium', getCategoryColor(doc.clean_rate_category)]">
                                {{ doc.clean_rate }}%
                            </span>
                        </div>
                        <div v-if="validationQueue.length === 0" class="p-4 text-center text-muted-foreground">
                            No pending validations
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Runs -->
            <div v-if="activeRuns.length > 0" class="rounded-xl border bg-card">
                <div class="border-b p-4">
                    <h2 class="font-semibold">Active Processing Runs</h2>
                </div>
                <div class="divide-y">
                    <div v-for="run in activeRuns" :key="run.id" class="p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium">{{ run.preset }}</span>
                            <span class="text-sm text-muted-foreground">
                                {{ run.completed + run.failed }} / {{ run.total }}
                            </span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-gray-200">
                            <div 
                                class="h-2 rounded-full bg-primary transition-all" 
                                :style="{ width: `${((run.completed + run.failed) / run.total) * 100}%` }"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="flex gap-4">
                <Link href="/process" class="rounded-lg bg-primary px-6 py-3 font-medium text-primary-foreground hover:bg-primary/90">
                    Process New Documents
                </Link>
                <Link href="/training/create" class="rounded-lg border px-6 py-3 font-medium hover:bg-accent">
                    Create Training Version
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
