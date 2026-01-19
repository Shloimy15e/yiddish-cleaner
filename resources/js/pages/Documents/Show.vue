<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import AlertError from '@/components/AlertError.vue';

interface Document {
    id: number;
    name: string;
    original_text: string;
    cleaned_text: string;
    status: string;
    error_message: string | null;
    clean_rate: number | null;
    clean_rate_category: string | null;
    metrics: Record<string, number> | null;
    validated_at: string | null;
    created_at: string;
    processing_run: {
        id: number;
        preset: string;
        mode: string;
    } | null;
}

const props = defineProps<{
    document: Document;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Documents', href: '/documents' },
    { title: props.document.name, href: `/documents/${props.document.id}` },
];

const activeView = ref<'cleaned' | 'original' | 'side-by-side'>('cleaned');

const getCategoryColor = (cat: string | null) => {
    const colors: Record<string, string> = {
        excellent: 'clean-rate-excellent',
        good: 'clean-rate-good',
        moderate: 'clean-rate-moderate',
        low: 'clean-rate-low',
        poor: 'clean-rate-poor',
    };
    return colors[cat ?? ''] ?? 'bg-muted text-muted-foreground';
};

const validateForm = useForm({});

const toggleValidation = () => {
    validateForm.patch(`/documents/${props.document.id}/validate`);
};

const deleteDocument = () => {
    if (confirm('Are you sure you want to delete this document?')) {
        router.delete(`/documents/${props.document.id}`);
    }
};

const formattedMetrics = computed(() => {
    if (!props.document.metrics) return [];
    return Object.entries(props.document.metrics).map(([key, value]) => ({
        name: key.replace(/_/g, ' '),
        value: typeof value === 'number' ? value.toFixed(2) : value,
    }));
});
</script>

<template>
    <Head :title="document.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            
            <!-- Error Alert -->
            <div v-if="document.status === 'failed' && document.error_message">
                <AlertError :errors="[document.error_message]" title="Processing Failed" />
            </div>

            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold">{{ document.name }}</h1>
                    <p class="text-muted-foreground">
                        Processed {{ document.created_at }}
                        <span v-if="document.processing_run">
                            · {{ document.processing_run.preset.replace(/_/g, ' ') }} ({{ document.processing_run.mode.replace(/_/g, ' ') }})
                        </span>
                    </p>
                </div>
                <div class="flex gap-2">
                    <button 
                        @click="toggleValidation"
                        :disabled="validateForm.processing"
                        :class="['rounded-lg px-4 py-2 font-medium', document.validated_at ? 'border hover:bg-muted' : 'bg-green-600 text-white hover:bg-green-700']"
                    >
                        {{ document.validated_at ? 'Unvalidate' : 'Validate' }}
                    </button>
                    <Link :href="`/documents/${document.id}/diff`" class="rounded-lg border px-4 py-2 font-medium hover:bg-muted">
                        View Diff
                    </Link>
                    <button @click="deleteDocument" class="rounded-lg border border-red-200 px-4 py-2 font-medium text-red-600 hover:bg-red-50">
                        Delete
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid gap-4 md:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Clean Rate</div>
                    <div class="flex items-center gap-2">
                        <span class="text-2xl font-bold">{{ document.clean_rate ?? '-' }}%</span>
                        <span v-if="document.clean_rate_category" :class="['rounded-full px-2 py-0.5 text-xs font-medium', getCategoryColor(document.clean_rate_category)]">
                            {{ document.clean_rate_category }}
                        </span>
                    </div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Original Length</div>
                    <div class="text-2xl font-bold">{{ document.original_text?.length || 0 }}</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Cleaned Length</div>
                    <div class="text-2xl font-bold">{{ document.cleaned_text?.length || 0 }}</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Status</div>
                    <div class="text-2xl font-bold">
                        <span v-if="document.validated_at" class="text-green-600">Validated</span>
                        <span v-else class="text-orange-600">Pending</span>
                    </div>
                </div>
            </div>

            <!-- Metrics -->
            <div v-if="formattedMetrics.length > 0" class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold mb-3">Processing Metrics</h2>
                <div class="flex flex-wrap gap-4">
                    <div v-for="metric in formattedMetrics" :key="metric.name" class="text-sm">
                        <span class="text-muted-foreground">{{ metric.name }}:</span>
                        <span class="ml-1 font-medium">{{ metric.value }}</span>
                    </div>
                </div>
            </div>

            <!-- View Toggle -->
            <div class="flex gap-2 border-b">
                <button 
                    @click="activeView = 'cleaned'"
                    :class="['px-4 py-2 font-medium border-b-2 -mb-px transition-colors', activeView === 'cleaned' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
                >
                    Cleaned Text
                </button>
                <button 
                    @click="activeView = 'original'"
                    :class="['px-4 py-2 font-medium border-b-2 -mb-px transition-colors', activeView === 'original' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
                >
                    Original Text
                </button>
                <button 
                    @click="activeView = 'side-by-side'"
                    :class="['px-4 py-2 font-medium border-b-2 -mb-px transition-colors', activeView === 'side-by-side' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
                >
                    Side by Side
                </button>
            </div>

            <!-- Text Content -->
            <div v-if="activeView === 'cleaned'" class="rounded-xl border bg-card p-4 min-h-64">
                <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.cleaned_text || 'No cleaned text available' }}</pre>
            </div>

            <div v-else-if="activeView === 'original'" class="rounded-xl border bg-card p-4 min-h-64">
                <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.original_text || 'No original text available' }}</pre>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border bg-card p-4 min-h-64">
                    <h3 class="font-semibold mb-2 text-muted-foreground">Original</h3>
                    <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.original_text || 'No original text' }}</pre>
                </div>
                <div class="rounded-xl border bg-card p-4 min-h-64">
                    <h3 class="font-semibold mb-2 text-muted-foreground">Cleaned</h3>
                    <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.cleaned_text || 'No cleaned text' }}</pre>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
