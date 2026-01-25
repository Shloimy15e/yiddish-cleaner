<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { ArrowsRightLeftIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline';
import { getWerColor } from '@/lib/asrMetrics';

interface ModelResult {
    wer: number;
    cer: number;
    hypothesis_text: string;
}

interface ComparisonRow {
    sample_id: number;
    sample_name: string;
    models: Record<string, ModelResult | null>;
}

interface ModelStats {
    avg_wer: number;
    avg_cer: number;
    count: number;
}

const props = defineProps<{
    availableModels: string[];
    selectedModels: string[];
    comparison: ComparisonRow[];
    modelStats: Record<string, ModelStats>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Benchmarks', href: '/benchmark' },
    { title: 'Compare', href: '/benchmark/compare' },
];

const selected = ref<string[]>(props.selectedModels);

const updateComparison = () => {
    if (selected.value.length >= 2) {
        router.get('/benchmark/compare', { models: selected.value.join(',') }, { preserveState: true });
    }
};

const getBestModel = (row: ComparisonRow): string | null => {
    let best: string | null = null;
    let bestWer = Infinity;
    for (const [model, result] of Object.entries(row.models)) {
        if (result && result.wer < bestWer) {
            bestWer = result.wer;
            best = model;
        }
    }
    return best;
};
</script>

<template>
    <Head title="Compare Models" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <!-- Header -->
            <div>
                <Link href="/benchmark" class="text-sm text-muted-foreground hover:text-foreground flex items-center gap-1 mb-2">
                    <ArrowLeftIcon class="w-4 h-4" />
                    Back to Leaderboard
                </Link>
                <h1 class="text-2xl font-bold flex items-center gap-2">
                    <ArrowsRightLeftIcon class="w-7 h-7 text-primary" />
                    Compare Models
                </h1>
                <p class="text-muted-foreground mt-1">
                    Side-by-side comparison of model performance on the same samples
                </p>
            </div>

            <!-- Model Selection -->
            <div class="rounded-xl border bg-card p-4">
                <h2 class="font-semibold mb-3">Select Models to Compare</h2>
                <div v-if="availableModels.length === 0" class="text-muted-foreground">
                    No models available. Add some benchmark transcriptions first.
                </div>
                <template v-else>
                    <div class="flex flex-wrap gap-2 mb-4">
                        <label 
                            v-for="model in availableModels" 
                            :key="model"
                            class="flex items-center gap-2 rounded-lg border px-3 py-2 cursor-pointer hover:bg-muted/50 transition-colors"
                            :class="{ 'bg-primary/10 border-primary': selected.includes(model) }"
                        >
                            <input 
                                type="checkbox" 
                                :value="model" 
                                v-model="selected"
                                class="rounded"
                            />
                            <span class="text-sm font-medium">{{ model }}</span>
                        </label>
                    </div>
                    <button 
                        @click="updateComparison"
                        :disabled="selected.length < 2"
                        class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Compare Selected ({{ selected.length }})
                    </button>
                </template>
            </div>

            <!-- Model Stats Summary -->
            <div v-if="selectedModels.length >= 2" class="grid gap-4" :style="{ gridTemplateColumns: `repeat(${Math.min(selectedModels.length, 4)}, 1fr)` }">
                <div v-for="model in selectedModels" :key="model" class="rounded-xl border bg-card p-4">
                    <h3 class="font-semibold truncate mb-2" :title="model">{{ model }}</h3>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <span class="text-muted-foreground">Avg WER:</span>
                                <span :class="['ml-1 font-bold', getWerColor(modelStats[model]?.avg_wer ?? 0, 'benchmark')]">
                                {{ modelStats[model]?.avg_wer ?? '-' }}%
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground">Samples:</span>
                            <span class="ml-1 font-medium">{{ modelStats[model]?.count ?? 0 }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Comparison Table -->
            <div v-if="comparison.length > 0" class="rounded-xl border bg-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-muted/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-medium">Sample</th>
                                <th v-for="model in selectedModels" :key="model" class="px-4 py-3 text-left text-sm font-medium">
                                    <span class="truncate block max-w-32" :title="model">{{ model }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in comparison" :key="row.sample_id" class="hover:bg-muted/30">
                                <td class="px-4 py-3">
                                    <Link :href="`/audio-samples/${row.sample_id}`" class="font-medium text-primary hover:underline">
                                        {{ row.sample_name }}
                                    </Link>
                                </td>
                                <td 
                                    v-for="model in selectedModels" 
                                    :key="model" 
                                    class="px-4 py-3"
                                    :class="{ 'bg-green-50 dark:bg-green-900/20': getBestModel(row) === model }"
                                >
                                    <template v-if="row.models[model]">
                                            <span :class="['font-bold', getWerColor(row.models[model]!.wer, 'benchmark')]">
                                            {{ row.models[model]!.wer }}%
                                        </span>
                                        <span class="text-xs text-muted-foreground ml-1">
                                            ({{ row.models[model]!.cer }}% CER)
                                        </span>
                                    </template>
                                    <span v-else class="text-muted-foreground">-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-else-if="selectedModels.length >= 2" class="rounded-xl border bg-card p-8 text-center text-muted-foreground">
                No samples have transcriptions from all selected models. Try selecting different models.
            </div>

            <div v-else-if="availableModels.length >= 2" class="rounded-xl border bg-card p-8 text-center text-muted-foreground">
                Select at least 2 models to compare their performance.
            </div>
        </div>
    </AppLayout>
</template>
