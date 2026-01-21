<script setup lang="ts">
import {
    CheckIcon,
    ClipboardDocumentIcon,
    PencilIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { computed } from 'vue';

type DiffSegment = { type: 'same' | 'removed' | 'added'; text: string };

const props = defineProps<{
    hasRawText: boolean;
    hasCleanedText: boolean;
    activeView: 'cleaned' | 'original' | 'side-by-side' | 'diff';
    copiedOriginal: boolean;
    copiedCleaned: boolean;
    isEditing: boolean;
    editedText: string;
    updateProcessing: boolean;
    originalText: string;
    cleanedText: string;
    charDiff: DiffSegment[];
    diffStats: { removed: number; added: number; unchanged: number };
}>();

const emit = defineEmits<{
    (e: 'update:activeView', value: 'cleaned' | 'original' | 'side-by-side' | 'diff'): void;
    (e: 'update:editedText', value: string): void;
    (e: 'startEditing'): void;
    (e: 'cancelEditing'): void;
    (e: 'saveEdit'): void;
    (e: 'copyOriginal'): void;
    (e: 'copyCleaned'): void;
}>();

const activeView = computed({
    get: () => props.activeView,
    set: (value) => emit('update:activeView', value),
});

const editedText = computed({
    get: () => props.editedText,
    set: (value) => emit('update:editedText', value),
});
</script>

<template>
    <div id="review-step" class="space-y-4">
        <div
            v-if="hasRawText || hasCleanedText"
            class="flex flex-col gap-4 border-b pb-2 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex gap-2">
                <button
                    v-if="hasCleanedText"
                    @click="activeView = 'cleaned'"
                    :class="[
                        '-mb-px border-b-2 px-4 py-2 font-medium transition-colors',
                        activeView === 'cleaned'
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    ]"
                >
                    Cleaned Text
                </button>
                <button
                    v-if="hasRawText"
                    @click="activeView = 'original'"
                    :class="[
                        '-mb-px border-b-2 px-4 py-2 font-medium transition-colors',
                        activeView === 'original'
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    ]"
                >
                    Original Text
                </button>
                <button
                    v-if="hasRawText && hasCleanedText"
                    @click="activeView = 'side-by-side'"
                    :class="[
                        '-mb-px border-b-2 px-4 py-2 font-medium transition-colors',
                        activeView === 'side-by-side'
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    ]"
                >
                    Side by Side
                </button>
                <button
                    v-if="hasRawText && hasCleanedText"
                    @click="activeView = 'diff'"
                    :class="[
                        '-mb-px border-b-2 px-4 py-2 font-medium transition-colors',
                        activeView === 'diff'
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    ]"
                >
                    Diff View
                </button>
            </div>
            <div class="flex gap-2">
                <button
                    v-if="hasRawText"
                    @click="emit('copyOriginal')"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors hover:bg-muted disabled:opacity-50"
                >
                    <CheckIcon v-if="copiedOriginal" class="h-4 w-4 text-green-500" />
                    <ClipboardDocumentIcon v-else class="h-4 w-4" />
                    {{ copiedOriginal ? 'Copied!' : 'Copy Original' }}
                </button>
                <button
                    v-if="hasCleanedText"
                    @click="emit('copyCleaned')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-emerald-700 disabled:opacity-50"
                >
                    <CheckIcon v-if="copiedCleaned" class="h-4 w-4" />
                    <ClipboardDocumentIcon v-else class="h-4 w-4" />
                    {{ copiedCleaned ? 'Copied!' : 'Copy Cleaned' }}
                </button>
            </div>
        </div>

        <div v-if="activeView === 'cleaned' && hasCleanedText" class="overflow-hidden rounded-xl border bg-card">
            <div class="flex items-center justify-between border-b bg-emerald-50 px-4 py-2 dark:bg-emerald-900/20">
                <h3 class="font-semibold text-emerald-700 dark:text-emerald-400">
                    Cleaned Text
                </h3>
                <div class="flex gap-2">
                    <button
                        v-if="!isEditing"
                        @click="emit('startEditing')"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1 text-sm font-medium hover:bg-muted"
                    >
                        <PencilIcon class="h-4 w-4" />
                        Edit
                    </button>
                    <template v-else>
                        <button
                            @click="emit('cancelEditing')"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1 text-sm font-medium hover:bg-muted"
                        >
                            <XMarkIcon class="h-4 w-4" />
                            Cancel
                        </button>
                        <button
                            @click="emit('saveEdit')"
                            :disabled="updateProcessing"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                            <CheckIcon class="h-4 w-4" />
                            Save
                        </button>
                    </template>
                </div>
            </div>
            <div class="max-h-150 min-h-64 overflow-y-auto p-4">
                <textarea
                    v-if="isEditing"
                    v-model="editedText"
                    dir="rtl"
                    class="h-96 w-full resize-y rounded-lg border bg-transparent p-2 font-mono text-sm"
                ></textarea>
                <pre
                    v-else
                    class="font-mono text-sm whitespace-pre-wrap"
                    dir="rtl"
                >{{ cleanedText }}</pre>
            </div>
        </div>

        <div
            v-else-if="activeView === 'original' && hasRawText"
            class="max-h-150 min-h-64 overflow-y-auto rounded-xl border bg-card p-4"
        >
            <pre class="font-mono text-sm whitespace-pre-wrap" dir="rtl">{{ originalText }}</pre>
        </div>

        <div v-else-if="activeView === 'side-by-side'" class="grid gap-4 md:grid-cols-2">
            <div class="flex max-h-150 min-h-64 flex-col overflow-hidden rounded-xl border bg-card">
                <div class="border-b bg-red-50 px-4 py-2 dark:bg-red-900/20">
                    <h3 class="font-semibold text-red-700 dark:text-red-400">Original</h3>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    <pre class="font-mono text-sm whitespace-pre-wrap" dir="rtl">
{{ originalText || 'No original text' }}</pre>
                </div>
            </div>
            <div class="flex max-h-150 min-h-64 flex-col overflow-hidden rounded-xl border bg-card">
                <div class="border-b bg-emerald-50 px-4 py-2 dark:bg-emerald-900/20">
                    <h3 class="font-semibold text-emerald-700 dark:text-emerald-400">Cleaned</h3>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    <pre class="font-mono text-sm whitespace-pre-wrap" dir="rtl">
{{ cleanedText || 'No cleaned text' }}</pre>
                </div>
            </div>
        </div>

        <div v-else-if="activeView === 'diff'" class="space-y-4">
            <div class="grid grid-cols-3 gap-4">
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Chars Removed</div>
                    <div class="text-2xl font-bold text-red-500">
                        {{ diffStats.removed.toLocaleString() }}
                    </div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Chars Added</div>
                    <div class="text-2xl font-bold text-teal-500">
                        {{ diffStats.added.toLocaleString() }}
                    </div>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="text-sm text-muted-foreground">Chars Unchanged</div>
                    <div class="text-2xl font-bold text-emerald-500">
                        {{ diffStats.unchanged.toLocaleString() }}
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <span class="rounded bg-red-500/20 px-2 py-0.5 text-red-600 line-through dark:text-red-400">removed</span>
                    <span>Removed</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="rounded bg-teal-500/20 px-2 py-0.5 text-teal-600 dark:text-teal-400">added</span>
                    <span>Added</span>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border bg-card">
                <div class="max-h-150 overflow-y-auto p-4" dir="rtl">
                    <div class="font-mono text-sm leading-relaxed whitespace-pre-wrap">
                        <template v-for="(segment, idx) in charDiff" :key="idx">
                            <span
                                v-if="segment.type === 'removed'"
                                class="bg-red-500/20 text-red-600 line-through decoration-red-500/50 dark:text-red-400"
                            >{{ segment.text }}</span>
                            <span
                                v-else-if="segment.type === 'added'"
                                class="bg-teal-500/20 text-teal-600 dark:text-teal-400"
                            >{{ segment.text }}</span>
                            <span v-else>{{ segment.text }}</span>
                        </template>
                    </div>
                    <div v-if="charDiff.length === 0" class="p-8 text-center text-muted-foreground">
                        No differences found
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
