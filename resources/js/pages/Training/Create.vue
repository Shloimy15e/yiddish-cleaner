<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import type { ColumnDef } from '@/components/ui/data-table/types';
import { getCleanRateCategoryClass } from '@/lib/cleanRate';
import { useTableSelection } from '@/composables/useTableSelection';
import { type BreadcrumbItem } from '@/types';
import type { TrainingDocument } from '@/types/training';

const props = defineProps<{
    availableDocuments: TrainingDocument[];
    latestVersion: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Training', href: '/training' },
    { title: 'Create Version', href: '/training/create' },
];

const form = useForm({
    version: '',
    name: '',
    min_clean_rate: 75,
    validated_only: true,
    document_ids: [] as number[],
});

const suggestedVersion = computed(() => {
    if (!props.latestVersion) return '1.0.0';
    const parts = props.latestVersion.split('.');
    parts[2] = String(parseInt(parts[2]) + 1);
    return parts.join('.');
});

// Pre-fill version
form.version = suggestedVersion.value;

const filteredDocuments = computed(() => {
    return props.availableDocuments.filter(doc => {
        if (form.validated_only && !doc.validated_at) return false;
        if (doc.clean_rate !== null && doc.clean_rate < form.min_clean_rate) return false;
        return true;
    });
});

const { selectedIds, selectedCount, allSelected } = useTableSelection(filteredDocuments);

// Sync selectedIds (Set) to form.document_ids (number[])
watch(selectedIds, (ids) => {
    form.document_ids = Array.from(ids) as number[];
}, { deep: true });

const columns: ColumnDef[] = [
    { key: 'name', label: 'Name' },
    { key: 'clean_rate', label: 'Clean Rate' },
    { key: 'status', label: 'Status' },
];

const submit = () => {
    form.post('/training');
};
</script>

<template>
    <Head title="Create Training Version" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <h1 class="text-2xl font-bold">Create Training Version</h1>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Version Info -->
                <div class="rounded-xl border bg-card p-6 space-y-4">
                    <h2 class="font-semibold">Version Information</h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium mb-2">Version Number</label>
                            <input
                                v-model="form.version"
                                type="text"
                                placeholder="1.0.0"
                                class="w-full rounded-lg border p-2"
                            />
                            <p v-if="form.errors.version" class="mt-1 text-sm text-red-600">{{ form.errors.version }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Name</label>
                            <input
                                v-model="form.name"
                                type="text"
                                placeholder="Training v1 - Full Clean"
                                class="w-full rounded-lg border p-2"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                    </div>
                </div>

                <!-- Criteria -->
                <div class="rounded-xl border bg-card p-6 space-y-4">
                    <h2 class="font-semibold">Selection Criteria</h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium mb-2">Minimum Clean Rate (%)</label>
                            <input
                                v-model.number="form.min_clean_rate"
                                type="range"
                                min="0"
                                max="100"
                                class="w-full"
                            />
                            <div class="flex justify-between text-sm text-muted-foreground">
                                <span>0%</span>
                                <span class="font-medium text-foreground">{{ form.min_clean_rate }}%</span>
                                <span>100%</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <input
                                v-model="form.validated_only"
                                type="checkbox"
                                id="validated_only"
                                class="h-4 w-4 rounded border-gray-300"
                            />
                            <label for="validated_only" class="text-sm font-medium">
                                Only include validated documents
                            </label>
                        </div>
                    </div>

                    <p class="text-sm text-muted-foreground">
                        {{ filteredDocuments.length }} documents match criteria
                    </p>
                </div>

                <!-- Document Selection -->
                <div>
                    <div class="flex items-center justify-between mb-2 px-1">
                        <h2 class="font-semibold">Select Documents ({{ selectedCount }} selected)</h2>
                        <button type="button" @click="() => { if (allSelected) { selectedIds = new Set(); } else { selectedIds = new Set(filteredDocuments.map(d => d.id)); } }" class="text-sm text-primary hover:underline">
                            {{ allSelected ? 'Deselect All' : 'Select All' }}
                        </button>
                    </div>

                    <div class="max-h-96 overflow-y-auto rounded-xl border bg-card">
                        <DataTable
                            :columns="columns"
                            :items="filteredDocuments"
                            item-key="id"
                            selectable
                            v-model:selected="selectedIds"
                            empty-message="No documents match the criteria"
                            class="border-0 rounded-none"
                            table-class="[&_thead]:sticky [&_thead]:top-0 [&_thead]:z-10"
                        >
                            <template #cell-name="{ item }">
                                <span class="font-medium">{{ item.name }}</span>
                            </template>

                            <template #cell-clean_rate="{ item }">
                                <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', getCleanRateCategoryClass(item.clean_rate_category as string | null)]">
                                    {{ item.clean_rate }}%
                                </span>
                            </template>

                            <template #cell-status="{ item }">
                                <span v-if="item.validated_at" class="text-green-600">Validated</span>
                                <span v-else class="text-muted-foreground">Pending</span>
                            </template>
                        </DataTable>
                    </div>
                </div>

                <p v-if="form.errors.document_ids" class="text-sm text-red-600">{{ form.errors.document_ids }}</p>

                <div class="flex gap-4">
                    <button
                        type="submit"
                        :disabled="form.processing || form.document_ids.length === 0"
                        class="rounded-lg bg-primary px-6 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Creating...' : 'Create Training Version' }}
                    </button>
                    <Link href="/training" class="rounded-lg border px-6 py-2 font-medium hover:bg-muted">
                        Cancel
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
