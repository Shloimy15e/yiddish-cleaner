<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    CheckCircleIcon,
    XCircleIcon,
    ClipboardDocumentIcon,
    ArrowDownTrayIcon,
    DocumentTextIcon,
    EyeIcon,
    XMarkIcon,
    CheckIcon,
} from '@heroicons/vue/24/outline';

interface ProcessedDocument {
    id: number;
    name: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    original_text: string;
    cleaned_text: string;
    status: string;
    error_message?: string;
}

const props = defineProps<{
    document: ProcessedDocument;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'process-another'): void;
}>();

const copiedCleaned = ref(false);
const copiedOriginal = ref(false);
const activeTab = ref<'cleaned' | 'original' | 'diff'>('cleaned');

const originalWords = computed(() => 
    props.document.original_text?.split(/\s+/).filter(Boolean).length || 0
);

const cleanedWords = computed(() => 
    props.document.cleaned_text?.split(/\s+/).filter(Boolean).length || 0
);

const removedWords = computed(() => originalWords.value - cleanedWords.value);

const reductionPercentage = computed(() => {
    if (originalWords.value === 0) return '0';
    return ((removedWords.value / originalWords.value) * 100).toFixed(1);
});

const getCategoryColor = (category: string | null) => {
    switch (category) {
        case 'excellent': return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
        case 'good': return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400';
        case 'fair': return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400';
        case 'poor': return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
        default: return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-400';
    }
};

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
        console.error('Failed to copy:', err);
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
    <div class="rounded-xl border bg-card overflow-hidden animate-in slide-in-from-bottom-4 duration-300">
        <!-- Header -->
        <div class="flex items-center justify-between border-b bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3">
            <div class="flex items-center gap-3">
                <CheckCircleIcon v-if="document.status === 'completed'" class="w-6 h-6 text-emerald-600" />
                <XCircleIcon v-else class="w-6 h-6 text-red-500" />
                <div>
                    <h3 class="font-semibold">{{ document.name }}</h3>
                    <p class="text-sm text-muted-foreground">Processing complete</p>
                </div>
            </div>
            <button 
                @click="$emit('close')"
                class="rounded-full p-2 hover:bg-muted transition-colors"
            >
                <XMarkIcon class="w-5 h-5" />
            </button>
        </div>

        <!-- Error State -->
        <div v-if="document.status === 'failed'" class="p-4 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800">
            <p class="text-sm text-red-700 dark:text-red-400">
                {{ document.error_message || 'An error occurred during processing.' }}
            </p>
        </div>

        <!-- Stats Grid -->
        <div v-if="document.status === 'completed'" class="grid grid-cols-4 gap-4 p-4 border-b">
            <div class="text-center">
                <div class="text-2xl font-bold">
                    {{ document.clean_rate ?? '-' }}%
                </div>
                <div class="text-xs text-muted-foreground">Clean Rate</div>
                <span 
                    v-if="document.clean_rate_category" 
                    :class="['inline-block mt-1 rounded-full px-2 py-0.5 text-xs font-medium capitalize', getCategoryColor(document.clean_rate_category)]"
                >
                    {{ document.clean_rate_category }}
                </span>
            </div>
            <div class="text-center">
                <div class="text-2xl font-bold text-blue-600">{{ reductionPercentage }}%</div>
                <div class="text-xs text-muted-foreground">Reduction</div>
            </div>
            <div class="text-center">
                <div class="text-2xl font-bold text-rose-500">{{ removedWords }}</div>
                <div class="text-xs text-muted-foreground">Words Removed</div>
            </div>
            <div class="text-center">
                <div class="text-2xl font-bold text-emerald-500">{{ cleanedWords }}</div>
                <div class="text-xs text-muted-foreground">Final Words</div>
            </div>
        </div>

        <!-- Content Tabs -->
        <div v-if="document.status === 'completed'" class="border-b">
            <div class="flex">
                <button
                    @click="activeTab = 'cleaned'"
                    :class="[
                        'flex-1 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
                        activeTab === 'cleaned' 
                            ? 'border-emerald-500 text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/10' 
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    ]"
                >
                    Cleaned Text
                </button>
                <button
                    @click="activeTab = 'original'"
                    :class="[
                        'flex-1 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
                        activeTab === 'original' 
                            ? 'border-primary text-primary' 
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    ]"
                >
                    Original Text
                </button>
            </div>
        </div>

        <!-- Text Content -->
        <div v-if="document.status === 'completed'" class="p-4">
            <div class="relative">
                <pre 
                    class="whitespace-pre-wrap font-mono text-sm max-h-64 overflow-y-auto rounded-lg bg-muted/30 p-4" 
                    dir="rtl"
                >{{ activeTab === 'cleaned' ? document.cleaned_text : document.original_text }}</pre>
                
                <!-- Copy Button Overlay -->
                <button
                    @click="activeTab === 'cleaned' ? copyCleanedText() : copyOriginalText()"
                    class="absolute top-2 right-2 inline-flex items-center gap-1.5 rounded-lg bg-background/90 backdrop-blur border px-3 py-1.5 text-xs font-medium hover:bg-muted transition-colors shadow-sm"
                >
                    <CheckIcon v-if="(activeTab === 'cleaned' && copiedCleaned) || (activeTab === 'original' && copiedOriginal)" class="w-3.5 h-3.5 text-green-500" />
                    <ClipboardDocumentIcon v-else class="w-3.5 h-3.5" />
                    {{ (activeTab === 'cleaned' && copiedCleaned) || (activeTab === 'original' && copiedOriginal) ? 'Copied!' : 'Copy' }}
                </button>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex flex-wrap items-center justify-between gap-3 border-t p-4 bg-muted/30">
            <div class="flex flex-wrap gap-2">
                <a 
                    :href="`/audio-samples/${document.id}/download`"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-sm font-medium hover:bg-emerald-700 transition-colors"
                >
                    <ArrowDownTrayIcon class="w-4 h-4" />
                    Download .docx
                </a>
                <a 
                    :href="`/audio-samples/${document.id}/download/text`"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted transition-colors"
                >
                    <DocumentTextIcon class="w-4 h-4" />
                    Download .txt
                </a>
                <Link
                    :href="`/audio-samples/${document.id}`"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted transition-colors"
                >
                    <EyeIcon class="w-4 h-4" />
                    View Details
                </Link>
                <Link
                    :href="`/audio-samples/${document.id}/diff`"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted transition-colors"
                >
                    View Diff
                </Link>
            </div>
            <button
                @click="$emit('process-another')"
                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted transition-colors"
            >
                Process Another
            </button>
        </div>
    </div>
</template>
