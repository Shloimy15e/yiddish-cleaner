<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import AlertError from '@/components/AlertError.vue';
import { 
    ClipboardDocumentIcon, 
    ArrowDownTrayIcon,
    CheckIcon,
    DocumentTextIcon,
    DocumentArrowDownIcon,
    ArrowsRightLeftIcon,
    SparklesIcon,
    CpuChipIcon,
    ArrowPathIcon,
    PencilIcon,
    XMarkIcon,
    CloudArrowUpIcon,
} from '@heroicons/vue/24/outline';

interface Preset {
    name: string;
    description: string;
    processors: string[];
}

interface AudioSample {
    id: number;
    name: string;
    reference_text_raw: string;
    reference_text_clean: string;
    status: string;
    error_message: string | null;
    clean_rate: number | null;
    clean_rate_category: string | null;
    metrics: Record<string, number> | null;
    removals: Array<{ type: string; original: string; count: number }> | null;
    validated_at: string | null;
    created_at: string;
    processing_run: {
        id: number;
        preset: string;
        mode: string;
    } | null;
}

const props = defineProps<{
    audioSample: AudioSample;
    presets: Record<string, Preset>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Audio Samples', href: '/audio-samples' },
    { title: props.audioSample.name, href: `/audio-samples/${props.audioSample.id}` },
];

// Status helpers
const isImported = computed(() => props.audioSample.status === 'imported');
const isCleaned = computed(() => props.audioSample.status === 'cleaned' || props.audioSample.status === 'validated');
const isValidated = computed(() => props.audioSample.status === 'validated');
const isPendingTranscript = computed(() => props.audioSample.status === 'pending_transcript');
const isCleaning = computed(() => props.audioSample.status === 'cleaning');
const isFailed = computed(() => props.audioSample.status === 'failed');
const hasRawText = computed(() => !!props.audioSample.reference_text_raw);
const hasCleanedText = computed(() => !!props.audioSample.reference_text_clean);
const canBeCleaned = computed(() => hasRawText.value && !isCleaning.value && !isPendingTranscript.value);
const canBeValidated = computed(() => hasCleanedText.value && !isValidated.value);

// View state
const activeView = ref<'cleaned' | 'original' | 'side-by-side' | 'diff'>('cleaned');
const diffViewMode = ref<'unified' | 'split'>('unified');
const copiedCleaned = ref(false);
const copiedOriginal = ref(false);
const isEditing = ref(false);
const editedText = ref('');

// Set default view based on status
watch(() => props.audioSample.status, (status) => {
    if (status === 'imported' || status === 'pending_transcript') {
        activeView.value = 'original';
    } else if (hasCleanedText.value) {
        activeView.value = 'diff';
    }
}, { immediate: true });

// Cleaning form
const cleanForm = useForm({
    preset: props.audioSample.processing_run?.preset ?? 'titles_only',
    mode: 'rule' as 'rule' | 'llm',
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
});

const presetOptions = computed(() => 
    Object.entries(props.presets).map(([key, value]) => ({
        id: key,
        name: value.name,
        description: value.description,
    }))
);

const submitClean = () => {
    cleanForm.post(`/audio-samples/${props.audioSample.id}/clean`, {
        preserveScroll: true,
    });
};

// Edit form
const updateForm = useForm({
    reference_text_clean: '',
});

const startEditing = () => {
    editedText.value = props.audioSample.reference_text_clean || '';
    isEditing.value = true;
};

const cancelEditing = () => {
    isEditing.value = false;
    editedText.value = '';
};

const saveEdit = () => {
    updateForm.reference_text_clean = editedText.value;
    updateForm.patch(`/audio-samples/${props.audioSample.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            isEditing.value = false;
        },
    });
};

// Transcript upload form
const transcriptForm = useForm({
    transcript: null as File | null,
});

const uploadTranscript = () => {
    if (!transcriptForm.transcript) return;
    transcriptForm.post(`/audio-samples/${props.audioSample.id}/transcript`, {
        preserveScroll: true,
        onSuccess: () => {
            transcriptForm.reset();
        },
    });
};

// Validation
const validateForm = useForm({});

const toggleValidation = () => {
    if (props.audioSample.validated_at) {
        router.delete(`/audio-samples/${props.audioSample.id}/validate`);
    } else {
        validateForm.post(`/audio-samples/${props.audioSample.id}/validate`);
    }
};

const deleteAudioSample = () => {
    if (confirm('Are you sure you want to delete this audio sample?')) {
        router.delete(`/audio-samples/${props.audioSample.id}`);
    }
};

// Color helpers
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
        pending_transcript: 'Needs Transcript',
        imported: 'Needs Cleaning',
        cleaning: 'Cleaning...',
        cleaned: 'Ready for Review',
        validated: 'Benchmark Ready',
        failed: 'Failed',
    };
    return labels[status] ?? status;
};

// Computed statistics
const originalWords = computed(() => {
    if (!props.audioSample.reference_text_raw) return 0;
    return props.audioSample.reference_text_raw.split(/\s+/).filter(w => w.length > 0).length;
});

const cleanedWords = computed(() => {
    if (!props.audioSample.reference_text_clean) return 0;
    return props.audioSample.reference_text_clean.split(/\s+/).filter(w => w.length > 0).length;
});

const removedWords = computed(() => originalWords.value - cleanedWords.value);

const reductionPercentage = computed(() => {
    if (originalWords.value === 0) return 0;
    return ((removedWords.value / originalWords.value) * 100).toFixed(1);
});

const formattedMetrics = computed(() => {
    if (!props.audioSample.metrics) return [];
    return Object.entries(props.audioSample.metrics).map(([key, value]) => ({
        name: key.replace(/_/g, ' '),
        value: typeof value === 'number' ? value.toFixed(2) : value,
    }));
});

// Diff algorithm using LCS
const computeDiff = (original: string[], cleaned: string[]): Array<{ type: 'same' | 'removed' | 'added'; text: string; lineNum?: number }> => {
    const m = original.length;
    const n = cleaned.length;
    
    const dp: number[][] = Array(m + 1).fill(null).map(() => Array(n + 1).fill(0));
    
    for (let i = 1; i <= m; i++) {
        for (let j = 1; j <= n; j++) {
            if (original[i - 1] === cleaned[j - 1]) {
                dp[i][j] = dp[i - 1][j - 1] + 1;
            } else {
                dp[i][j] = Math.max(dp[i - 1][j], dp[i][j - 1]);
            }
        }
    }
    
    const result: Array<{ type: 'same' | 'removed' | 'added'; text: string; lineNum?: number }> = [];
    let i = m, j = n;
    const temp: typeof result = [];
    
    while (i > 0 || j > 0) {
        if (i > 0 && j > 0 && original[i - 1] === cleaned[j - 1]) {
            temp.unshift({ type: 'same', text: original[i - 1], lineNum: i });
            i--;
            j--;
        } else if (j > 0 && (i === 0 || dp[i][j - 1] >= dp[i - 1][j])) {
            temp.unshift({ type: 'added', text: cleaned[j - 1], lineNum: j });
            j--;
        } else if (i > 0) {
            temp.unshift({ type: 'removed', text: original[i - 1], lineNum: i });
            i--;
        }
    }
    
    return temp;
};

const diffLines = computed(() => {
    const original = props.audioSample.reference_text_raw?.split('\n') || [];
    const cleaned = props.audioSample.reference_text_clean?.split('\n') || [];
    return computeDiff(original, cleaned);
});

const diffStats = computed(() => {
    const lines = diffLines.value;
    return {
        removed: lines.filter(l => l.type === 'removed').length,
        added: lines.filter(l => l.type === 'added').length,
        unchanged: lines.filter(l => l.type === 'same').length,
    };
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
    if (props.audioSample.reference_text_clean) {
        copyToClipboard(props.audioSample.reference_text_clean, 'cleaned');
    }
};

const copyOriginalText = () => {
    if (props.audioSample.reference_text_raw) {
        copyToClipboard(props.audioSample.reference_text_raw, 'original');
    }
};
</script>

<template>
    <Head :title="audioSample.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            
            <!-- Error Alert -->
            <div v-if="isFailed && audioSample.error_message">
                <AlertError :errors="[audioSample.error_message]" title="Processing Failed" />
            </div>

            <!-- Header -->
            <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3 mb-1">
                        <h1 class="text-2xl font-bold">{{ audioSample.name }}</h1>
                        <span :class="['rounded-full px-3 py-1 text-xs font-medium', getStatusColor(audioSample.status)]">
                            {{ getStatusLabel(audioSample.status) }}
                        </span>
                    </div>
                    <p class="text-muted-foreground">
                        Created {{ audioSample.created_at }}
                        <span v-if="audioSample.processing_run">
                            · {{ audioSample.processing_run.preset.replace(/_/g, ' ') }}
                        </span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <!-- Download Dropdown (only if has cleaned text) -->
                    <div v-if="hasCleanedText" class="relative group">
                        <button class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90">
                            <ArrowDownTrayIcon class="w-4 h-4" />
                            Download
                        </button>
                        <div class="absolute right-0 mt-1 w-48 rounded-lg border bg-popover shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-10">
                            <a 
                                :href="`/audio-samples/${audioSample.id}/download`"
                                class="flex items-center gap-2 px-4 py-2 hover:bg-muted rounded-t-lg"
                            >
                                <DocumentArrowDownIcon class="w-4 h-4" />
                                Cleaned (.docx)
                            </a>
                            <a 
                                :href="`/audio-samples/${audioSample.id}/download/text`"
                                class="flex items-center gap-2 px-4 py-2 hover:bg-muted"
                            >
                                <DocumentTextIcon class="w-4 h-4" />
                                Cleaned (.txt)
                            </a>
                            <a 
                                v-if="hasRawText"
                                :href="`/audio-samples/${audioSample.id}/download/original`"
                                class="flex items-center gap-2 px-4 py-2 hover:bg-muted rounded-b-lg"
                            >
                                <DocumentTextIcon class="w-4 h-4" />
                                Original (.txt)
                            </a>
                        </div>
                    </div>
                    
                    <!-- Validate Button (only if cleaned) -->
                    <button 
                        v-if="canBeValidated"
                        @click="toggleValidation"
                        :disabled="validateForm.processing"
                        class="rounded-lg bg-green-600 text-white px-4 py-2 font-medium hover:bg-green-700 disabled:opacity-50"
                    >
                        Mark as Benchmark Ready
                    </button>
                    <button 
                        v-else-if="isValidated"
                        @click="toggleValidation"
                        :disabled="validateForm.processing"
                        class="rounded-lg border px-4 py-2 font-medium hover:bg-muted"
                    >
                        Remove from Benchmark Ready
                    </button>
                    
                    <button @click="deleteAudioSample" class="rounded-lg border border-red-200 px-4 py-2 font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                        Delete
                    </button>
                </div>
            </div>

            <!-- Pending Transcript: Upload Form -->
            <div v-if="isPendingTranscript" class="rounded-xl border-2 border-dashed border-yellow-300 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-900/20 p-6">
                <div class="flex items-start gap-4">
                    <CloudArrowUpIcon class="w-8 h-8 text-yellow-600 dark:text-yellow-400 flex-shrink-0" />
                    <div class="flex-1">
                        <h2 class="text-lg font-semibold text-yellow-800 dark:text-yellow-300">Upload Reference Transcript</h2>
                        <p class="text-sm text-yellow-700 dark:text-yellow-400 mb-4">
                            This audio sample needs a reference transcript before it can be cleaned.
                        </p>
                        <form @submit.prevent="uploadTranscript" class="flex gap-3 items-end">
                            <div class="flex-1">
                                <label class="block text-sm font-medium mb-1">Transcript File</label>
                                <input 
                                    type="file" 
                                    accept=".txt,.docx,.pdf"
                                    @change="(e: any) => transcriptForm.transcript = e.target.files[0]"
                                    class="block w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-primary file:text-primary-foreground file:font-medium hover:file:bg-primary/90"
                                />
                            </div>
                            <button 
                                type="submit" 
                                :disabled="!transcriptForm.transcript || transcriptForm.processing"
                                class="rounded-lg bg-yellow-600 text-white px-4 py-2 font-medium hover:bg-yellow-700 disabled:opacity-50"
                            >
                                Upload
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Imported: Cleaning Form -->
            <div v-if="isImported && canBeCleaned" class="rounded-xl border-2 border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 p-6">
                <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                    <SparklesIcon class="w-5 h-5 text-blue-600" />
                    Clean This Transcript
                </h2>
                <form @submit.prevent="submitClean" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <!-- Preset Selection -->
                        <div>
                            <label class="block text-sm font-medium mb-1">Cleaning Preset</label>
                            <select 
                                v-model="cleanForm.preset"
                                class="w-full rounded-lg border bg-background px-3 py-2"
                            >
                                <option v-for="preset in presetOptions" :key="preset.id" :value="preset.id">
                                    {{ preset.name }}
                                </option>
                            </select>
                            <p class="text-xs text-muted-foreground mt-1">
                                {{ presets[cleanForm.preset]?.description }}
                            </p>
                        </div>

                        <!-- Mode Selection -->
                        <div>
                            <label class="block text-sm font-medium mb-1">Cleaning Mode</label>
                            <div class="flex gap-2">
                                <button 
                                    type="button"
                                    @click="cleanForm.mode = 'rule'"
                                    :class="['flex-1 flex items-center justify-center gap-2 rounded-lg px-4 py-2 font-medium border transition-colors', cleanForm.mode === 'rule' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']"
                                >
                                    <CpuChipIcon class="w-4 h-4" />
                                    Rule-based
                                </button>
                                <button 
                                    type="button"
                                    @click="cleanForm.mode = 'llm'"
                                    :class="['flex-1 flex items-center justify-center gap-2 rounded-lg px-4 py-2 font-medium border transition-colors', cleanForm.mode === 'llm' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']"
                                >
                                    <SparklesIcon class="w-4 h-4" />
                                    AI (LLM)
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- LLM Options (if LLM mode) -->
                    <div v-if="cleanForm.mode === 'llm'" class="grid gap-4 md:grid-cols-2 pt-2 border-t">
                        <div>
                            <label class="block text-sm font-medium mb-1">LLM Provider</label>
                            <select v-model="cleanForm.llm_provider" class="w-full rounded-lg border bg-background px-3 py-2">
                                <option value="openrouter">OpenRouter</option>
                                <option value="anthropic">Anthropic</option>
                                <option value="openai">OpenAI</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Model</label>
                            <input 
                                v-model="cleanForm.llm_model" 
                                type="text"
                                class="w-full rounded-lg border bg-background px-3 py-2"
                                placeholder="anthropic/claude-sonnet-4"
                            />
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button 
                            type="submit"
                            :disabled="cleanForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-blue-600 text-white px-6 py-2 font-medium hover:bg-blue-700 disabled:opacity-50"
                        >
                            <ArrowPathIcon v-if="cleanForm.processing" class="w-4 h-4 animate-spin" />
                            <SparklesIcon v-else class="w-4 h-4" />
                            {{ cleanForm.processing ? 'Cleaning...' : 'Clean Transcript' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Re-clean option (if already cleaned) -->
            <details v-if="isCleaned && canBeCleaned" class="rounded-xl border bg-card">
                <summary class="px-4 py-3 cursor-pointer font-medium flex items-center gap-2 hover:bg-muted/50">
                    <ArrowPathIcon class="w-4 h-4" />
                    Re-clean with different settings
                </summary>
                <div class="px-4 pb-4">
                    <form @submit.prevent="submitClean" class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium mb-1">Cleaning Preset</label>
                                <select v-model="cleanForm.preset" class="w-full rounded-lg border bg-background px-3 py-2">
                                    <option v-for="preset in presetOptions" :key="preset.id" :value="preset.id">
                                        {{ preset.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Mode</label>
                                <div class="flex gap-2">
                                    <button type="button" @click="cleanForm.mode = 'rule'" :class="['flex-1 rounded-lg px-3 py-2 border', cleanForm.mode === 'rule' ? 'bg-primary text-primary-foreground' : '']">
                                        Rule-based
                                    </button>
                                    <button type="button" @click="cleanForm.mode = 'llm'" :class="['flex-1 rounded-lg px-3 py-2 border', cleanForm.mode === 'llm' ? 'bg-primary text-primary-foreground' : '']">
                                        AI (LLM)
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-if="cleanForm.mode === 'llm'" class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium mb-1">Provider</label>
                                <select v-model="cleanForm.llm_provider" class="w-full rounded-lg border bg-background px-3 py-2">
                                    <option value="openrouter">OpenRouter</option>
                                    <option value="anthropic">Anthropic</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Model</label>
                                <input v-model="cleanForm.llm_model" class="w-full rounded-lg border bg-background px-3 py-2" />
                            </div>
                        </div>
                        <p class="text-sm text-amber-600 dark:text-amber-400">
                            ⚠️ Re-cleaning will overwrite the current cleaned text and remove validation status.
                        </p>
                        <button type="submit" :disabled="cleanForm.processing" class="rounded-lg bg-amber-600 text-white px-4 py-2 font-medium hover:bg-amber-700 disabled:opacity-50">
                            {{ cleanForm.processing ? 'Cleaning...' : 'Re-clean' }}
                        </button>
                    </form>
                </div>
            </details>

            <!-- Stats Grid (only if cleaned) -->
            <div v-if="hasCleanedText" class="grid gap-4 grid-cols-2 md:grid-cols-5">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Clean Rate</div>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-2xl font-bold">{{ audioSample.clean_rate ?? '-' }}%</span>
                        <span v-if="audioSample.clean_rate_category" :class="['rounded-full px-2 py-0.5 text-xs font-medium capitalize', getCategoryColor(audioSample.clean_rate_category)]">
                            {{ audioSample.clean_rate_category }}
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

            <!-- Additional Metrics -->
            <div v-if="formattedMetrics.length > 0" class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold mb-3">Processing Metrics</h2>
                <div class="flex flex-wrap gap-4">
                    <div v-for="metric in formattedMetrics" :key="metric.name" class="text-sm">
                        <span class="text-muted-foreground">{{ metric.name }}:</span>
                        <span class="ml-1 font-medium">{{ metric.value }}</span>
                    </div>
                </div>
            </div>

            <!-- Removals Summary -->
            <div v-if="audioSample.removals && audioSample.removals.length > 0" class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold mb-3">What Was Removed</h2>
                <div class="flex flex-wrap gap-2">
                    <span 
                        v-for="removal in audioSample.removals" 
                        :key="removal.type" 
                        class="rounded-full bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400 px-3 py-1 text-sm"
                    >
                        {{ removal.type }}: {{ removal.count }}×
                    </span>
                </div>
            </div>

            <!-- View Toggle with Copy Buttons -->
            <div v-if="hasRawText || hasCleanedText" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b pb-2">
                <div class="flex gap-2">
                    <button 
                        v-if="hasCleanedText"
                        @click="activeView = 'cleaned'"
                        :class="['px-4 py-2 font-medium border-b-2 -mb-px transition-colors', activeView === 'cleaned' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
                    >
                        Cleaned Text
                    </button>
                    <button 
                        v-if="hasRawText"
                        @click="activeView = 'original'"
                        :class="['px-4 py-2 font-medium border-b-2 -mb-px transition-colors', activeView === 'original' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
                    >
                        Original Text
                    </button>
                    <button 
                        v-if="hasRawText && hasCleanedText"
                        @click="activeView = 'side-by-side'"
                        :class="['px-4 py-2 font-medium border-b-2 -mb-px transition-colors', activeView === 'side-by-side' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
                    >
                        Side by Side
                    </button>
                    <button 
                        v-if="hasRawText && hasCleanedText"
                        @click="activeView = 'diff'"
                        :class="['px-4 py-2 font-medium border-b-2 -mb-px transition-colors', activeView === 'diff' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']"
                    >
                        Diff View
                    </button>
                </div>
                <div class="flex gap-2">
                    <button 
                        v-if="hasRawText"
                        @click="copyOriginalText"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-50 transition-colors"
                    >
                        <CheckIcon v-if="copiedOriginal" class="w-4 h-4 text-green-500" />
                        <ClipboardDocumentIcon v-else class="w-4 h-4" />
                        {{ copiedOriginal ? 'Copied!' : 'Copy Original' }}
                    </button>
                    <button 
                        v-if="hasCleanedText"
                        @click="copyCleanedText"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-sm font-medium hover:bg-emerald-700 disabled:opacity-50 transition-colors"
                    >
                        <CheckIcon v-if="copiedCleaned" class="w-4 h-4" />
                        <ClipboardDocumentIcon v-else class="w-4 h-4" />
                        {{ copiedCleaned ? 'Copied!' : 'Copy Cleaned' }}
                    </button>
                </div>
            </div>

            <!-- Cleaned Text View (with inline edit) -->
            <div v-if="activeView === 'cleaned' && hasCleanedText" class="rounded-xl border bg-card overflow-hidden">
                <div class="flex items-center justify-between px-4 py-2 border-b bg-emerald-50 dark:bg-emerald-900/20">
                    <h3 class="font-semibold text-emerald-700 dark:text-emerald-400">Cleaned Text</h3>
                    <div class="flex gap-2">
                        <button 
                            v-if="!isEditing"
                            @click="startEditing"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1 text-sm font-medium hover:bg-muted"
                        >
                            <PencilIcon class="w-4 h-4" />
                            Edit
                        </button>
                        <template v-else>
                            <button 
                                @click="cancelEditing"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1 text-sm font-medium hover:bg-muted"
                            >
                                <XMarkIcon class="w-4 h-4" />
                                Cancel
                            </button>
                            <button 
                                @click="saveEdit"
                                :disabled="updateForm.processing"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 text-white px-3 py-1 text-sm font-medium hover:bg-emerald-700 disabled:opacity-50"
                            >
                                <CheckIcon class="w-4 h-4" />
                                Save
                            </button>
                        </template>
                    </div>
                </div>
                <div class="p-4 min-h-64 max-h-[600px] overflow-y-auto">
                    <textarea 
                        v-if="isEditing"
                        v-model="editedText"
                        dir="rtl"
                        class="w-full h-96 font-mono text-sm bg-transparent border rounded-lg p-2 resize-y"
                    ></textarea>
                    <pre v-else class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ audioSample.reference_text_clean }}</pre>
                </div>
            </div>

            <!-- Original Text View -->
            <div v-else-if="activeView === 'original' && hasRawText" class="rounded-xl border bg-card p-4 min-h-64 max-h-[600px] overflow-y-auto">
                <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ audioSample.reference_text_raw }}</pre>
            </div>

            <!-- Side by Side View -->
            <div v-else-if="activeView === 'side-by-side'" class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border bg-card min-h-64 max-h-[600px] overflow-hidden flex flex-col">
                    <div class="px-4 py-2 border-b bg-red-50 dark:bg-red-900/20">
                        <h3 class="font-semibold text-red-700 dark:text-red-400">Original</h3>
                    </div>
                    <div class="p-4 overflow-y-auto flex-1">
                        <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ audioSample.reference_text_raw || 'No original text' }}</pre>
                    </div>
                </div>
                <div class="rounded-xl border bg-card min-h-64 max-h-[600px] overflow-hidden flex flex-col">
                    <div class="px-4 py-2 border-b bg-emerald-50 dark:bg-emerald-900/20">
                        <h3 class="font-semibold text-emerald-700 dark:text-emerald-400">Cleaned</h3>
                    </div>
                    <div class="p-4 overflow-y-auto flex-1">
                        <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ audioSample.reference_text_clean || 'No cleaned text' }}</pre>
                    </div>
                </div>
            </div>

            <!-- Diff View -->
            <div v-else-if="activeView === 'diff'" class="space-y-4">
                <!-- Diff Stats -->
                <div class="grid gap-4 grid-cols-3">
                    <div class="rounded-xl border bg-card p-4">
                        <div class="text-sm text-muted-foreground">Lines Removed</div>
                        <div class="text-2xl font-bold text-red-500">{{ diffStats.removed }}</div>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <div class="text-sm text-muted-foreground">Lines Changed</div>
                        <div class="text-2xl font-bold text-teal-500">{{ diffStats.added }}</div>
                    </div>
                    <div class="rounded-xl border bg-card p-4">
                        <div class="text-sm text-muted-foreground">Lines Unchanged</div>
                        <div class="text-2xl font-bold text-emerald-500">{{ diffStats.unchanged }}</div>
                    </div>
                </div>

                <!-- Diff View Toggle & Legend -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex gap-2">
                        <button 
                            @click="diffViewMode = 'unified'"
                            :class="['inline-flex items-center gap-2 px-4 py-2 font-medium rounded-lg transition-colors', diffViewMode === 'unified' ? 'bg-primary text-primary-foreground' : 'border hover:bg-muted']"
                        >
                            <DocumentTextIcon class="w-4 h-4" />
                            Unified
                        </button>
                        <button 
                            @click="diffViewMode = 'split'"
                            :class="['inline-flex items-center gap-2 px-4 py-2 font-medium rounded-lg transition-colors', diffViewMode === 'split' ? 'bg-primary text-primary-foreground' : 'border hover:bg-muted']"
                        >
                            <ArrowsRightLeftIcon class="w-4 h-4" />
                            Split
                        </button>
                    </div>
                    <div class="flex gap-4 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-red-500/20 border border-red-500/50"></span>
                            <span>Removed</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded bg-teal-500/20 border border-teal-500/50"></span>
                            <span>Added/Changed</span>
                        </div>
                    </div>
                </div>

                <!-- Unified Diff -->
                <div v-if="diffViewMode === 'unified'" class="rounded-xl border bg-card overflow-hidden">
                    <div class="max-h-[500px] overflow-y-auto font-mono text-sm" dir="rtl">
                        <div 
                            v-for="(line, idx) in diffLines" 
                            :key="idx"
                            :class="[
                                'flex items-start px-4 py-1 border-b border-border/50',
                                line.type === 'removed' ? 'bg-red-500/10' : '',
                                line.type === 'added' ? 'bg-teal-500/10' : '',
                            ]"
                        >
                            <span :class="[
                                'w-6 flex-shrink-0 text-center select-none',
                                line.type === 'removed' ? 'text-red-500' : '',
                                line.type === 'added' ? 'text-teal-500' : 'text-muted-foreground',
                            ]">
                                {{ line.type === 'removed' ? '-' : line.type === 'added' ? '+' : '' }}
                            </span>
                            <span :class="[
                                'flex-1',
                                line.type === 'removed' ? 'text-red-600 dark:text-red-400 line-through' : '',
                                line.type === 'added' ? 'text-teal-600 dark:text-teal-400' : '',
                            ]">
                                {{ line.text || '\u00A0' }}
                            </span>
                        </div>
                        <div v-if="diffLines.length === 0" class="p-8 text-center text-muted-foreground">
                            No differences found
                        </div>
                    </div>
                </div>

                <!-- Split Diff -->
                <div v-else class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-xl border bg-card overflow-hidden">
                        <div class="border-b px-4 py-3 bg-red-50 dark:bg-red-900/20">
                            <h3 class="font-semibold text-red-700 dark:text-red-400">Original</h3>
                            <p class="text-xs text-muted-foreground">{{ originalWords }} words</p>
                        </div>
                        <div class="p-4 max-h-[500px] overflow-y-auto">
                            <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ audioSample.reference_text_raw }}</pre>
                        </div>
                    </div>
                    <div class="rounded-xl border bg-card overflow-hidden">
                        <div class="border-b px-4 py-3 bg-emerald-50 dark:bg-emerald-900/20">
                            <h3 class="font-semibold text-emerald-700 dark:text-emerald-400">Cleaned</h3>
                            <p class="text-xs text-muted-foreground">{{ cleanedWords }} words</p>
                        </div>
                        <div class="p-4 max-h-[500px] overflow-y-auto">
                            <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ audioSample.reference_text_clean }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
