<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
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

// Simple diff visualization - highlight removed content
const diffLines = computed(() => {
    const original = props.document.original_text?.split('\n') || [];
    const cleaned = props.document.cleaned_text?.split('\n') || [];
    
    const result: Array<{ type: 'same' | 'removed' | 'added'; text: string }> = [];
    
    let origIdx = 0;
    let cleanIdx = 0;
    
    while (origIdx < original.length || cleanIdx < cleaned.length) {
        const origLine = original[origIdx] || '';
        const cleanLine = cleaned[cleanIdx] || '';
        
        if (origLine === cleanLine) {
            result.push({ type: 'same', text: origLine });
            origIdx++;
            cleanIdx++;
        } else if (origIdx < original.length && !cleaned.includes(origLine)) {
            result.push({ type: 'removed', text: origLine });
            origIdx++;
        } else if (cleanIdx < cleaned.length) {
            result.push({ type: 'added', text: cleanLine });
            cleanIdx++;
            // Check if we should also advance original
            if (origIdx < original.length && origLine !== cleanLine) {
                // Skip original line that was modified
            }
        } else {
            origIdx++;
        }
    }
    
    return result;
});
</script>

<template>
    <Head :title="`Diff - ${document.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Diff View</h1>
                    <p class="text-muted-foreground">{{ document.name }}</p>
                </div>
                <div class="flex gap-2">
                    <Link :href="`/documents/${document.id}`" class="rounded-lg border px-4 py-2 font-medium hover:bg-muted transition-colors">
                        Back to Document
                    </Link>
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
                    <div class="text-sm text-muted-foreground">Characters Removed</div>
                    <div class="text-2xl font-bold text-secondary">
                        {{ (document.original_text?.length || 0) - (document.cleaned_text?.length || 0) }}
                    </div>
                </div>
            </div>

            <!-- Removals Summary -->
            <div v-if="document.removals && document.removals.length > 0" class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold mb-3">Removals Summary</h2>
                <div class="flex flex-wrap gap-2">
                    <span v-for="removal in document.removals" :key="removal.type" class="rounded-full bg-secondary/15 text-secondary px-3 py-1 text-sm">
                        {{ removal.type }}: {{ removal.count }}
                    </span>
                </div>
            </div>

            <!-- Legend -->
            <div class="flex gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <span class="w-4 h-4 rounded bg-red-500/20 border border-red-500/50"></span>
                    <span>Removed</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-4 h-4 rounded bg-teal-500/20 border border-teal-500/50"></span>
                    <span>Added/Changed</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-4 h-4 rounded bg-muted border border-border"></span>
                    <span>Unchanged</span>
                </div>
            </div>

            <!-- Diff View -->
            <div class="rounded-xl border bg-card overflow-hidden">
                <div class="border-b p-4">
                    <h2 class="font-semibold">Changes</h2>
                </div>
                <div class="p-4 max-h-[600px] overflow-y-auto font-mono text-sm" dir="rtl">
                    <div 
                        v-for="(line, idx) in diffLines" 
                        :key="idx"
                        :class="[
                            'px-2 py-0.5 rounded',
                            line.type === 'removed' ? 'bg-red-500/15 text-red-400 line-through' : '',
                            line.type === 'added' ? 'bg-teal-500/15 text-teal-400' : '',
                            line.type === 'same' ? '' : '',
                        ]"
                    >
                        <span v-if="line.type === 'removed'" class="text-red-500 mr-2">-</span>
                        <span v-if="line.type === 'added'" class="text-teal-500 mr-2">+</span>
                        {{ line.text || '&nbsp;' }}
                    </div>
                </div>
            </div>

            <!-- Side by Side -->
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border bg-card">
                    <div class="border-b p-4 bg-red-500/10">
                        <h3 class="font-semibold text-red-400">Original</h3>
                    </div>
                    <div class="p-4 max-h-96 overflow-y-auto">
                        <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.original_text }}</pre>
                    </div>
                </div>
                <div class="rounded-xl border bg-card">
                    <div class="border-b p-4 bg-teal-500/10">
                        <h3 class="font-semibold text-teal-400">Cleaned</h3>
                    </div>
                    <div class="p-4 max-h-96 overflow-y-auto">
                        <pre class="whitespace-pre-wrap font-mono text-sm" dir="rtl">{{ document.cleaned_text }}</pre>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
