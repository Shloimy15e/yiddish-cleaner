<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { 
    ClipboardDocumentIcon, 
    ArrowDownTrayIcon,
    CheckIcon,
    ArrowsRightLeftIcon,
    DocumentTextIcon,
} from '@heroicons/vue/24/outline';
import { type BreadcrumbItem } from '@/types';

interface Document {
    id: number;
    name: string;
    original_text: string;
    cleaned_text: string;
    clean_rate: number | null;
    clean_rate_category: string | null;
    metrics: Record<string, number> | null;
    removals: Array<{ type: string; original: string; count: number }> | null;
}

const props = defineProps<{
    document: Document;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Documents', href: '/documents' },
    { title: props.document.name, href: `/documents/${props.document.id}` },
    { title: 'Diff', href: `/documents/${props.document.id}/diff` },
];

const viewMode = ref<'unified' | 'split'>('unified');
const copiedCleaned = ref(false);

const getCategoryColor = (cat: string | null) => {
    const colors: Record<string, string> = {
        excellent: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        good: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        moderate: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
        low: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
        poor: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    };
    return colors[cat ?? ''] ?? 'bg-muted text-muted-foreground';
};

// Word-level statistics
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

// Improved diff algorithm using LCS (Longest Common Subsequence)
const computeDiff = (original: string[], cleaned: string[]): Array<{ type: 'same' | 'removed' | 'added'; text: string; lineNum?: number }> => {
    const m = original.length;
    const n = cleaned.length;
    
    // Build LCS matrix
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
    
    // Backtrack to build diff
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
    const original = props.document.original_text?.split('\n') || [];
    const cleaned = props.document.cleaned_text?.split('\n') || [];
    return computeDiff(original, cleaned);
});

// Stats for diff
const diffStats = computed(() => {
    const lines = diffLines.value;
    return {
        removed: lines.filter(l => l.type === 'removed').length,
        added: lines.filter(l => l.type === 'added').length,
        unchanged: lines.filter(l => l.type === 'same').length,
    };
});

// Inline word-level diff for similar lines
const getInlineWordDiff = (original: string, cleaned: string): Array<{ text: string; type: 'same' | 'removed' | 'added' }> => {
    const origWords = original.split(/(\s+)/);
    const cleanWords = cleaned.split(/(\s+)/);
    return computeDiff(origWords, cleanWords).map(d => ({ text: d.text, type: d.type }));
};

const copyCleanedText = async () => {
    if (props.document.cleaned_text) {
        try {
            await navigator.clipboard.writeText(props.document.cleaned_text);
            copiedCleaned.value = true;
            setTimeout(() => copiedCleaned.value = false, 2000);
        } catch (err) {
            console.error('Failed to copy:', err);
        }
    }
};
</script>

<template>
    <Head :title="`Diff - ${document.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold">Diff View</h1>
                    <p class="text-muted-foreground">{{ document.name }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        @click="copyCleanedText"
                        class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 font-medium hover:bg-muted transition-colors"
                    >
                        <CheckIcon v-if="copiedCleaned" class="w-4 h-4 text-green-500" />
                        <ClipboardDocumentIcon v-else class="w-4 h-4" />
                        {{ copiedCleaned ? 'Copied!' : 'Copy Cleaned' }}
                    </button>
                    <a 
                        :href="`/documents/${document.id}/download`"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
                    >
                        <ArrowDownTrayIcon class="w-4 h-4" />
                        Download
                    </a>
                    <Link :href="`/documents/${document.id}`" class="rounded-lg border px-4 py-2 font-medium hover:bg-muted transition-colors">
                        Back to Document
                    </Link>
                </div>
            </div>

            <!-- Stats Grid - Enhanced -->
            <div class="grid gap-4 grid-cols-2 md:grid-cols-6">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Clean Rate</div>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-2xl font-bold">{{ document.clean_rate ?? '-' }}%</span>
                    </div>
                    <span v-if="document.clean_rate_category" :class="['inline-block mt-1 rounded-full px-2 py-0.5 text-xs font-medium capitalize', getCategoryColor(document.clean_rate_category)]">
                        {{ document.clean_rate_category }}
                    </span>
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

            <!-- Removals Summary -->
            <div v-if="document.removals && document.removals.length > 0" class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold mb-3">What Was Removed</h2>
                <div class="flex flex-wrap gap-2">
                    <span 
                        v-for="removal in document.removals" 
                        :key="removal.type" 
                        class="rounded-full bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400 px-3 py-1 text-sm"
                    >
                        {{ removal.type }}: {{ removal.count }}×
                    </span>
                </div>
            </div>

            <!-- View Toggle & Legend -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b pb-2">
                <div class="flex gap-2">
                    <button 
                        @click="viewMode = 'unified'"
                        :class="['inline-flex items-center gap-2 px-4 py-2 font-medium rounded-lg transition-colors', viewMode === 'unified' ? 'bg-primary text-primary-foreground' : 'border hover:bg-muted']"
                    >
                        <DocumentTextIcon class="w-4 h-4" />
                        Unified
                    </button>
                    <button 
                        @click="viewMode = 'split'"
                        :class="['inline-flex items-center gap-2 px-4 py-2 font-medium rounded-lg transition-colors', viewMode === 'split' ? 'bg-primary text-primary-foreground' : 'border hover:bg-muted']"
                    >
                        <ArrowsRightLeftIcon class="w-4 h-4" />
                        Side by Side
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
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded bg-muted border border-border"></span>
                        <span>Unchanged</span>
                    </div>
                </div>
            </div>

            <!-- Unified Diff View -->
            <div v-if="viewMode === 'unified'" class="rounded-xl border bg-card overflow-hidden">
                <div class="border-b px-4 py-3 bg-muted/30">
                    <h2 class="font-semibold">Changes</h2>
                </div>
                <div class="max-h-[600px] overflow-y-auto font-mono text-sm" dir="rtl">
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

            <!-- Side by Side View -->
            <div v-else class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border bg-card overflow-hidden">
                    <div class="border-b px-4 py-3 bg-red-50 dark:bg-red-900/20">
                        <h3 class="font-semibold text-red-700 dark:text-red-400">Original</h3>
                        <p class="text-xs text-muted-foreground">{{ originalWords }} words</p>
                    </div>
                    <div class="p-4 max-h-[500px] overflow-y-auto">
                        <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.original_text }}</pre>
                    </div>
                </div>
                <div class="rounded-xl border bg-card overflow-hidden">
                    <div class="border-b px-4 py-3 bg-emerald-50 dark:bg-emerald-900/20">
                        <h3 class="font-semibold text-emerald-700 dark:text-emerald-400">Cleaned</h3>
                        <p class="text-xs text-muted-foreground">{{ cleanedWords }} words</p>
                    </div>
                    <div class="p-4 max-h-[500px] overflow-y-auto">
                        <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.cleaned_text }}</pre>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
