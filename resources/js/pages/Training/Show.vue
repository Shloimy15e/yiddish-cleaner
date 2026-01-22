<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { getCleanRateCategoryClass } from '@/lib/cleanRate';
import { type BreadcrumbItem } from '@/types';
import type { TrainingVersionDetail } from '@/types/training';

const props = defineProps<{
    version: TrainingVersionDetail;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Training', href: '/training' },
    { title: props.version.name, href: `/training/${props.version.id}` },
];

const deleteVersion = () => {
    if (confirm('Are you sure you want to delete this training version?')) {
        router.delete(`/training/${props.version.id}`);
    }
};
</script>

<template>
    <Head :title="version.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold">{{ version.name }}</h1>
                    <p class="text-muted-foreground">
                        Version {{ version.version }} · Created {{ version.created_at }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <a 
                        :href="`/training/${version.id}/export`" 
                        class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        Export Dataset
                    </a>
                    <button 
                        @click="deleteVersion" 
                        class="rounded-lg border border-red-200 px-4 py-2 font-medium text-red-600 hover:bg-red-50"
                    >
                        Delete
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Documents</div>
                    <div class="text-2xl font-bold">{{ version.document_count }}</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Status</div>
                    <div class="text-2xl font-bold">
                        <span v-if="version.is_active" class="text-green-600">Active</span>
                        <span v-else class="text-muted-foreground">Archived</span>
                    </div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Criteria</div>
                    <div class="flex flex-wrap gap-1 mt-1">
                        <span v-if="version.criteria?.min_clean_rate" class="rounded bg-muted px-2 py-0.5 text-xs">
                            Min {{ version.criteria.min_clean_rate }}%
                        </span>
                        <span v-if="version.criteria?.validated_only" class="rounded bg-muted px-2 py-0.5 text-xs">
                            Validated only
                        </span>
                        <span v-if="!version.criteria" class="text-muted-foreground text-sm">-</span>
                    </div>
                </div>
            </div>

            <!-- Documents Table -->
            <div class="rounded-xl border bg-card overflow-hidden">
                <div class="border-b p-4">
                    <h2 class="font-semibold">Included Documents</h2>
                </div>
                <table class="w-full">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium">Name</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Clean Rate</th>
                            <th class="px-4 py-3 text-left text-sm font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="doc in version.documents" :key="doc.id" class="hover:bg-muted/30">
                            <td class="px-4 py-3 font-medium">{{ doc.name }}</td>
                            <td class="px-4 py-3">
                                <span v-if="doc.clean_rate !== null" :class="['rounded-full px-2 py-0.5 text-xs font-medium', getCleanRateCategoryClass(doc.clean_rate_category)]">
                                    {{ doc.clean_rate }}%
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <Link :href="`/documents/${doc.id}`" class="text-sm text-primary hover:underline">
                                    View
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="version.documents.length === 0">
                            <td colspan="3" class="px-4 py-8 text-center text-muted-foreground">
                                No documents in this version
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
