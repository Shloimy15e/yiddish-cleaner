<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ArrowPathIcon } from '@heroicons/vue/24/outline';
import { computed, reactive, ref, watch } from 'vue';

import { tokenize } from '@/lib/transcriptionUtils';

interface Props {
    isOpen: boolean;
    referenceText: string;
    hypothesisText: string;
    audioSampleId: number;
    transcriptionId: number;
    initialRefStart: number | null;
    initialRefEnd: number | null;
    initialHypStart: number | null;
    initialHypEnd: number | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    close: [];
}>();

// Form data as simple reactive state
const formData = reactive({
    ref_start: null as number | null,
    ref_end: null as number | null,
    hyp_start: null as number | null,
    hyp_end: null as number | null,
});

// Interactive selection state
type SelectionMode = 'ref-start' | 'ref-end' | 'hyp-start' | 'hyp-end' | null;
const selectionMode = ref<SelectionMode>(null);

// Tokenized words for display
const refWords = computed(() => tokenize(props.referenceText));
const hypWords = computed(() => tokenize(props.hypothesisText));

const totalRefWords = computed(() => refWords.value.length);
const totalHypWords = computed(() => hypWords.value.length);

// Initialize form when modal opens
watch(() => props.isOpen, (open) => {
    if (open) {
        formData.ref_start = props.initialRefStart;
        formData.ref_end = props.initialRefEnd;
        formData.hyp_start = props.initialHypStart;
        formData.hyp_end = props.initialHypEnd;
        selectionMode.value = null;
    }
});

// Unified word class helper
const getWordClass = (index: number, type: 'ref' | 'hyp') => {
    const total = type === 'ref' ? totalRefWords.value : totalHypWords.value;
    const start = (type === 'ref' ? formData.ref_start : formData.hyp_start) ?? 0;
    const end = (type === 'ref' ? formData.ref_end : formData.hyp_end) ?? (total - 1);
    const inRange = index >= start && index <= end;
    const isStart = index === start;
    const isEnd = index === end;
    const isSelecting = selectionMode.value === `${type}-start` || selectionMode.value === `${type}-end`;

    return [
        'inline-block rounded px-1 py-0.5 text-sm cursor-pointer transition-shadow',
        isSelecting ? 'hover:ring-2 hover:ring-primary' : '',
        inRange ? 'bg-primary/20' : 'bg-muted/50 opacity-50',
        isStart && isEnd ? 'ring-2 ring-purple-500' : isStart ? 'ring-2 ring-green-500' : isEnd ? 'ring-2 ring-red-500' : '',
    ];
};

// Unified word click handler
const handleWordClick = (index: number, type: 'ref' | 'hyp') => {
    const startKey = `${type}_start` as 'ref_start' | 'hyp_start';
    const endKey = `${type}_end` as 'ref_end' | 'hyp_end';

    if (selectionMode.value === `${type}-start`) {
        formData[startKey] = index;
        if (formData[endKey] !== null && formData[endKey]! < index) {
            formData[endKey] = index;
        }
        selectionMode.value = null;
    } else if (selectionMode.value === `${type}-end`) {
        formData[endKey] = index;
        if (formData[startKey] !== null && formData[startKey]! > index) {
            formData[startKey] = index;
        }
        selectionMode.value = null;
    }
};

const formAction = computed(() =>
    route('transcriptions.recalculate', { audioSample: props.audioSampleId, transcription: props.transcriptionId }),
);

const resetRange = () => {
    formData.ref_start = null;
    formData.ref_end = null;
    formData.hyp_start = null;
    formData.hyp_end = null;
    selectionMode.value = null;
};

const closeModal = () => {
    selectionMode.value = null;
    emit('close');
};
</script>

<template>
    <Modal :show="isOpen" max-width="4xl" panel-class="flex max-h-[90vh] flex-col p-6" @close="closeModal">
        <div class="shrink-0 text-lg font-semibold">WER Calculation Range</div>
        <p class="mt-1 shrink-0 text-sm text-muted-foreground">
            Click on words to set start/end points, or enter indices manually. Selected range is highlighted.
        </p>

        <!-- Legend -->
        <div class="mt-3 flex shrink-0 flex-wrap gap-4 text-xs">
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 rounded ring-2 ring-green-500"></span>
                <span>Start</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 rounded ring-2 ring-red-500"></span>
                <span>End</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 rounded bg-primary/20"></span>
                <span>In Range</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="h-3 w-3 rounded bg-muted/50 opacity-50"></span>
                <span>Excluded</span>
            </div>
        </div>

        <Form
            :action="formAction"
            method="post"
            :options="{ preserveScroll: true, only: ['transcription'] }"
            @success="closeModal"
            class="mt-4 flex flex-1 flex-col gap-4 overflow-hidden"
            #default="{ processing }"
        >
            <input type="hidden" name="ref_start" :value="formData.ref_start">
            <input type="hidden" name="ref_end" :value="formData.ref_end">
            <input type="hidden" name="hyp_start" :value="formData.hyp_start">
            <input type="hidden" name="hyp_end" :value="formData.hyp_end">

            <!-- Scrollable content area -->
            <div class="flex-1 space-y-4 overflow-y-auto pr-2">
                <!-- Reference Text Section -->
                <div class="space-y-2">
                    <div class="sticky top-0 z-10 flex items-center justify-between bg-card py-1">
                        <div class="text-sm font-medium">Reference Text</div>
                        <div class="text-xs text-muted-foreground">
                            {{ totalRefWords }} words | Range: {{ formData.ref_start ?? 0 }}-{{ formData.ref_end ?? (totalRefWords - 1) }}
                        </div>
                    </div>

                    <!-- Selection buttons -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            @click="selectionMode = selectionMode === 'ref-start' ? null : 'ref-start'"
                            :class="['rounded px-2 py-1 text-xs font-medium transition-colors', selectionMode === 'ref-start' ? 'bg-green-500 text-white' : 'bg-muted hover:bg-muted/80']"
                        >
                            {{ selectionMode === 'ref-start' ? 'Click a word...' : 'Set Start' }}
                        </button>
                        <button
                            type="button"
                            @click="selectionMode = selectionMode === 'ref-end' ? null : 'ref-end'"
                            :class="['rounded px-2 py-1 text-xs font-medium transition-colors', selectionMode === 'ref-end' ? 'bg-red-500 text-white' : 'bg-muted hover:bg-muted/80']"
                        >
                            {{ selectionMode === 'ref-end' ? 'Click a word...' : 'Set End' }}
                        </button>
                        <div class="flex-1"></div>
                        <div class="flex items-center gap-1">
                            <input
                                v-model.number="formData.ref_start"
                                type="number"
                                min="0"
                                :max="totalRefWords - 1"
                                placeholder="Start"
                                class="w-16 rounded border bg-background px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                            <span class="text-xs text-muted-foreground">to</span>
                            <input
                                v-model.number="formData.ref_end"
                                type="number"
                                :min="formData.ref_start ?? 0"
                                :max="totalRefWords - 1"
                                placeholder="End"
                                class="w-16 rounded border bg-background px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>

                    <!-- Interactive word display -->
                    <div class="max-h-28 overflow-y-auto rounded-lg border bg-muted/30 p-3 sm:max-h-48" dir="rtl">
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="(word, idx) in refWords"
                                :key="`ref-word-${idx}`"
                                :class="getWordClass(idx, 'ref')"
                                @click="handleWordClick(idx, 'ref')"
                                v-tippy="`Word ${idx}`"
                            >{{ word }}</span>
                        </div>
                    </div>
                </div>

                <!-- Hypothesis Text Section -->
                <div class="space-y-2">
                    <div class="sticky top-0 z-10 flex items-center justify-between bg-card py-1">
                        <div class="text-sm font-medium">Hypothesis Text</div>
                        <div class="text-xs text-muted-foreground">
                            {{ totalHypWords }} words | Range: {{ formData.hyp_start ?? 0 }}-{{ formData.hyp_end ?? (totalHypWords - 1) }}
                        </div>
                    </div>

                    <!-- Selection buttons -->
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            @click="selectionMode = selectionMode === 'hyp-start' ? null : 'hyp-start'"
                            :class="['rounded px-2 py-1 text-xs font-medium transition-colors', selectionMode === 'hyp-start' ? 'bg-green-500 text-white' : 'bg-muted hover:bg-muted/80']"
                        >
                            {{ selectionMode === 'hyp-start' ? 'Click a word...' : 'Set Start' }}
                        </button>
                        <button
                            type="button"
                            @click="selectionMode = selectionMode === 'hyp-end' ? null : 'hyp-end'"
                            :class="['rounded px-2 py-1 text-xs font-medium transition-colors', selectionMode === 'hyp-end' ? 'bg-red-500 text-white' : 'bg-muted hover:bg-muted/80']"
                        >
                            {{ selectionMode === 'hyp-end' ? 'Click a word...' : 'Set End' }}
                        </button>
                        <div class="flex-1"></div>
                        <div class="flex items-center gap-1">
                            <input
                                v-model.number="formData.hyp_start"
                                type="number"
                                min="0"
                                :max="totalHypWords - 1"
                                placeholder="Start"
                                class="w-16 rounded border bg-background px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                            <span class="text-xs text-muted-foreground">to</span>
                            <input
                                v-model.number="formData.hyp_end"
                                type="number"
                                :min="formData.hyp_start ?? 0"
                                :max="totalHypWords - 1"
                                placeholder="End"
                                class="w-16 rounded border bg-background px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>

                    <!-- Interactive word display -->
                    <div class="max-h-28 overflow-y-auto rounded-lg border bg-muted/30 p-3 sm:max-h-48" dir="rtl">
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="(word, idx) in hypWords"
                                :key="`hyp-word-${idx}`"
                                :class="getWordClass(idx, 'hyp')"
                                @click="handleWordClick(idx, 'hyp')"
                                v-tippy="`Word ${idx}`"
                            >{{ word }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions - Fixed at bottom -->
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-2 border-t pt-3">
                <button
                    type="button"
                    @click="resetRange"
                    class="text-sm text-muted-foreground hover:text-foreground"
                >
                    Reset to Full Text
                </button>
                <div class="flex gap-2">
                    <button
                        type="button"
                        @click="closeModal"
                        class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        <ArrowPathIcon v-if="processing" class="h-4 w-4 animate-spin" />
                        Recalculate WER
                    </button>
                </div>
            </div>
        </Form>
    </Modal>
</template>
