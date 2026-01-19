<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import AlertError from '@/components/AlertError.vue';
import { 
    ClipboardDocumentIcon, 
    ArrowDownTrayIcon,
    CheckIcon,
    DocumentTextIcon,
    DocumentArrowDownIcon,
} from '@heroicons/vue/24/outline';

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
const copiedCleaned = ref(false);
const copiedOriginal = ref(false);

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
    if (props.document.validated_at) {
        router.delete(`/documents/${props.document.id}/validate`);
    } else {
        validateForm.post(`/documents/${props.document.id}/validate`);
    }
};

const deleteDocument = () => {
    if (confirm('Are you sure you want to delete this document?')) {
        router.delete(`/documents/${props.document.id}`);
    }
};

// Computed statistics
const originalWords = computed(() => {
    if (!props.document.original_text) return 0;
    return props.document.original_text.split(/\s+/).filter(w => w.length > 0).length;
});

const cleanedWords = computed(() => {
    if (!props.document.cleaned_text) return 0;
    return props.document.cleaned_text.split(/\s+/).filter(w => w.length > 0).length;
});

const removedWords = computed(() => originalWords.value - cleanedWords.value);

const reductionPercentage = computed(() => {
    if (originalWords.value === 0) return 0;
    return ((removedWords.value / originalWords.value) * 100).toFixed(1);
});

const formattedMetrics = computed(() => {
    if (!props.document.metrics) return [];
    return Object.entries(props.document.metrics).map(([key, value]) => ({
        name: key.replace(/_/g, ' '),
        value: typeof value === 'number' ? value.toFixed(2) : value,
    }));
});

// Copy to clipboard
const copyToClipboard = async (text: string, type: 'cleaned' | 'original') => {
    try {
        await navigator.clipboard.writeText(text);
        if (type === 'cleaned') {
            copiedCleaned.value = true;
            setTimeout(() => copiedCleaned.value = false, 2000);
        } else {
            copiedOriginal.value = true;
            setTimeout(() => copiedOriginal.value = false, 2000);
        }
    } catch (err) {
        console.error('Failed to copy text:', err);
    }
};

const copyCleanedText = () => {
    if (props.document.cleaned_text) {
        copyToClipboard(props.document.cleaned_text, 'cleaned');
    }
};

const copyOriginalText = () => {
    if (props.document.original_text) {
        copyToClipboard(props.document.original_text, 'original');
    }
};
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
            <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold">{{ document.name }}</h1>
                    <p class="text-muted-foreground">
                        Processed {{ document.created_at }}
                        <span v-if="document.processing_run">
                            · {{ document.processing_run.preset.replace(/_/g, ' ') }} ({{ document.processing_run.mode.replace(/_/g, ' ') }})
                        </span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <!-- Download Dropdown -->
                    <div class="relative group">
                        <button class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90">
                            <ArrowDownTrayIcon class="w-4 h-4" />
                            Download
                        </button>
                        <div class="absolute right-0 mt-1 w-48 rounded-lg border bg-popover shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-10">
                            <a 
                                :href="`/documents/${document.id}/download`"
                                class="flex items-center gap-2 px-4 py-2 hover:bg-muted rounded-t-lg"
                            >
                                <DocumentArrowDownIcon class="w-4 h-4" />
                                Cleaned (.docx)
                            </a>
                            <a 
                                :href="`/documents/${document.id}/download/text`"
                                class="flex items-center gap-2 px-4 py-2 hover:bg-muted"
                            >
                                <DocumentTextIcon class="w-4 h-4" />
                                Cleaned (.txt)
                            </a>
                            <a 
                                :href="`/documents/${document.id}/download/original`"
                                class="flex items-center gap-2 px-4 py-2 hover:bg-muted rounded-b-lg"
                            >
                                <DocumentTextIcon class="w-4 h-4" />
                                Original (.txt)
                            </a>
                        </div>
                    </div>
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

            <!-- Stats Grid - Enhanced -->
            <div class="grid gap-4 grid-cols-2 md:grid-cols-5">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Clean Rate</div>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-2xl font-bold">{{ document.clean_rate ?? '-' }}%</span>
                        <span v-if="document.clean_rate_category" :class="['rounded-full px-2 py-0.5 text-xs font-medium capitalize', getCategoryColor(document.clean_rate_category)]">
                            {{ document.clean_rate_category }}
                        </span>
                    </div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Reduction</div>
                    <div class="text-2xl font-bold text-blue-600">{{ reductionPercentage }}%</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Words Removed</div>
                    <div class="text-2xl font-bold text-rose-500">{{ removedWords }}</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Original Words</div>
                    <div class="text-2xl font-bold">{{ originalWords }}</div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Cleaned Words</div>
                    <div class="text-2xl font-bold text-emerald-500">{{ cleanedWords }}</div>
                </div>
            </div>

            <!-- Additional Metrics (if available) -->
            <div v-if="formattedMetrics.length > 0" class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold mb-3">Processing Metrics</h2>
                <div class="flex flex-wrap gap-4">
                    <div v-for="metric in formattedMetrics" :key="metric.name" class="text-sm">
                        <span class="text-muted-foreground">{{ metric.name }}:</span>
                        <span class="ml-1 font-medium">{{ metric.value }}</span>
                    </div>
                </div>
            </div>

            <!-- View Toggle with Copy Buttons -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b pb-2">
                <div class="flex gap-2">
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
                <div class="flex gap-2">
                    <button 
                        @click="copyOriginalText"
                        :disabled="!document.original_text"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-50 transition-colors"
                    >
                        <CheckIcon v-if="copiedOriginal" class="w-4 h-4 text-green-500" />
                        <ClipboardDocumentIcon v-else class="w-4 h-4" />
                        {{ copiedOriginal ? 'Copied!' : 'Copy Original' }}
                    </button>
                    <button 
                        @click="copyCleanedText"
                        :disabled="!document.cleaned_text"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-sm font-medium hover:bg-emerald-700 disabled:opacity-50 transition-colors"
                    >
                        <CheckIcon v-if="copiedCleaned" class="w-4 h-4" />
                        <ClipboardDocumentIcon v-else class="w-4 h-4" />
                        {{ copiedCleaned ? 'Copied!' : 'Copy Cleaned' }}
                    </button>
                </div>
            </div>

            <!-- Text Content -->
            <div v-if="activeView === 'cleaned'" class="rounded-xl border bg-card p-4 min-h-64 max-h-[600px] overflow-y-auto">
                <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.cleaned_text || 'No cleaned text available' }}</pre>
            </div>

            <div v-else-if="activeView === 'original'" class="rounded-xl border bg-card p-4 min-h-64 max-h-[600px] overflow-y-auto">
                <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.original_text || 'No original text available' }}</pre>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border bg-card min-h-64 max-h-[600px] overflow-hidden flex flex-col">
                    <div class="px-4 py-2 border-b bg-red-50 dark:bg-red-900/20">
                        <h3 class="font-semibold text-red-700 dark:text-red-400">Original</h3>
                    </div>
                    <div class="p-4 overflow-y-auto flex-1">
                        <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.original_text || 'No original text' }}</pre>
                    </div>
                </div>
                <div class="rounded-xl border bg-card min-h-64 max-h-[600px] overflow-hidden flex flex-col">
                    <div class="px-4 py-2 border-b bg-emerald-50 dark:bg-emerald-900/20">
                        <h3 class="font-semibold text-emerald-700 dark:text-emerald-400">Cleaned</h3>
                    </div>
                    <div class="p-4 overflow-y-auto flex-1">
                        <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.cleaned_text || 'No cleaned text' }}</pre>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
