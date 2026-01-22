<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { PaginatedTrainingVersion } from '@/types/training';

const props = withDefaults(defineProps<{
    versions?: PaginatedTrainingVersion | null;
}>(), {
    versions: () => ({ data: [], current_page: 1, last_page: 1, per_page: 10, total: 0 } as PaginatedTrainingVersion),
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Training', href: route('training.index') },
];

const deleteVersion = (id: number) => {
    if (confirm('Are you sure you want to delete this training version?')) {
        router.delete(`/training/${id}`);
    }
};
</script>

<template>
    <Head title="Training Versions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Training Versions</h1>
                <Link href="/training/create" class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90">
                    Create Version
                </Link>
            </div>

            <p class="text-muted-foreground">
                Training versions are curated collections of validated documents that can be exported for ASR model training.
            </p>

            <!-- Versions Grid -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="version in versions?.data" :key="version.id" class="rounded-xl border bg-card p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="font-semibold">{{ version.name }}</h3>
                            <p class="text-sm text-muted-foreground">v{{ version.version }}</p>
                        </div>
                        <span v-if="version.is_active" class="rounded-full bg-green-100 text-green-800 px-2 py-0.5 text-xs font-medium">
                            Active
                        </span>
                    </div>
                    
                    <div class="space-y-2 mb-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">Documents</span>
                            <span class="font-medium">{{ version.document_count }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">Created</span>
                            <span>{{ version.created_at }}</span>
                        </div>
                    </div>

                    <div v-if="version.criteria" class="mb-4 text-sm">
                        <span class="text-muted-foreground">Criteria:</span>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <span v-if="version.criteria.min_clean_rate" class="rounded bg-muted px-2 py-0.5 text-xs">
                                Min {{ version.criteria.min_clean_rate }}%
                            </span>
                            <span v-if="version.criteria.validated_only" class="rounded bg-muted px-2 py-0.5 text-xs">
                                Validated only
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-2 pt-4 border-t">
                        <Link :href="`/training/${version.id}`" class="text-sm text-primary hover:underline">
                            View
                        </Link>
                        <a :href="`/training/${version.id}/export`" class="text-sm text-primary hover:underline">
                            Export
                        </a>
                        <button @click="deleteVersion(version.id)" class="text-sm text-red-600 hover:underline">
                            Delete
                        </button>
                    </div>
                </div>

                <div v-if="versions.length === 0" class="col-span-full text-center py-12 text-muted-foreground">
                    No training versions yet. Create one to get started.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
