<script setup lang="ts">
import {
    CheckCircleIcon,
    DocumentTextIcon,
    LinkIcon,
    MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import type { BaseTranscription } from '@/types/transcriptions';

const props = defineProps<{
    isOpen: boolean;
    audioSampleId: number;
    audioSampleName?: string;
}>();

const emit = defineEmits<{
    close: [];
    linked: [transcription: BaseTranscription];
}>();

// Search and filter state
const searchQuery = ref('');
const statusFilter = ref<'all' | 'validated' | 'unvalidated'>('all');
const loading = ref(false);
const transcriptions = ref<BaseTranscription[]>([]);
const selectedId = ref<number | null>(null);

// Form for linking
const linkForm = useForm({
    audio_sample_id: props.audioSampleId,
});

// Fetch orphan transcriptions
const fetchTranscriptions = async () => {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (searchQuery.value) params.append('search', searchQuery.value);
        if (statusFilter.value !== 'all')
            params.append('status', statusFilter.value);

        const response = await fetch(
            `/transcriptions/orphan-list?${params.toString()}`,
        );
        const data = await response.json();
        transcriptions.value = Array.isArray(data)
            ? data
            : data.transcriptions || [];
    } catch (error) {
        console.error('Failed to fetch transcriptions:', error);
        transcriptions.value = [];
    } finally {
        loading.value = false;
    }
};

// Debounce search
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(fetchTranscriptions, 300);
});

watch(statusFilter, fetchTranscriptions);

// Fetch when modal opens
watch(
    () => props.isOpen,
    (isOpen) => {
        if (isOpen) {
            searchQuery.value = '';
            statusFilter.value = 'all';
            selectedId.value = null;
            fetchTranscriptions();
        }
    },
);

// Handle link
const handleLink = () => {
    if (!selectedId.value) return;

    router.post(
        `/transcriptions/${selectedId.value}/link`,
        {
            audio_sample_id: props.audioSampleId,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                const linked = transcriptions.value.find(
                    (t) => t.id === selectedId.value,
                );
                if (linked) emit('linked', linked);
                emit('close');
            },
        },
    );
};

// Close handler
const close = () => {
    emit('close');
};

// Format date
const formatDate = (dateString: string | null) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

// Selected transcription
const selectedTranscription = computed(() =>
    transcriptions.value.find((t) => t.id === selectedId.value),
);
</script>

<template>
    <Modal
        :show="isOpen"
        max-width="2xl"
        panel-class="overflow-hidden"
        @close="close"
    >
        <template #title>Link Base Transcription</template>

        <!-- Content -->
        <div class="p-6">
            <p class="mb-4 text-sm text-muted-foreground">
                Select an orphan base transcription to link to
                <strong>{{
                    audioSampleName || `Audio Sample #${audioSampleId}`
                }}</strong
                >.
            </p>

            <!-- Search and Filter -->
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:gap-3">
                <div class="relative flex-1">
                    <MagnifyingGlassIcon
                        class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search by name..."
                        class="w-full rounded-lg border bg-background py-2 pr-3 pl-9 text-sm"
                    />
                </div>
                <SelectMenu
                    v-model="statusFilter"
                    :options="[
                        { value: 'all', label: 'All Status' },
                        { value: 'validated', label: 'Validated' },
                        { value: 'unvalidated', label: 'Unvalidated' },
                    ]"
                    class="w-full sm:w-40"
                />
            </div>

            <!-- Transcription List -->
            <div class="max-h-80 overflow-y-auto rounded-lg border">
                <div
                    v-if="loading"
                    class="flex items-center justify-center py-8"
                >
                    <div
                        class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"
                    ></div>
                </div>

                <div
                    v-else-if="transcriptions.length === 0"
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    No orphan transcriptions found.
                </div>

                <div v-else class="divide-y">
                    <button
                        v-for="transcription in transcriptions"
                        :key="transcription.id"
                        @click="selectedId = transcription.id"
                        :class="[
                            'w-full px-4 py-3 text-left transition-colors hover:bg-muted',
                            selectedId === transcription.id
                                ? 'bg-primary/10 ring-2 ring-primary ring-inset'
                                : '',
                        ]"
                    >
                        <div class="flex items-start gap-3">
                            <DocumentTextIcon
                                class="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate font-medium">
                                        {{
                                            transcription.name ||
                                            `Transcription #${transcription.id}`
                                        }}
                                    </span>
                                    <CheckCircleIcon
                                        v-if="transcription.validated_at"
                                        class="h-4 w-4 shrink-0 text-emerald-600"
                                    />
                                </div>
                                <div
                                    class="mt-1 flex items-center gap-3 text-xs text-muted-foreground"
                                >
                                    <span
                                        v-if="transcription.clean_rate !== null"
                                        class="font-mono"
                                    >
                                        {{ transcription.clean_rate }}% clean
                                    </span>
                                    <span>{{
                                        formatDate(transcription.created_at)
                                    }}</span>
                                </div>
                                <p
                                    v-if="transcription.text_clean"
                                    class="mt-1 line-clamp-2 text-xs text-muted-foreground"
                                    dir="auto"
                                >
                                    {{ transcription.text_clean.slice(0, 150)
                                    }}{{
                                        transcription.text_clean.length > 150
                                            ? '...'
                                            : ''
                                    }}
                                </p>
                            </div>
                        </div>
                    </button>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm text-muted-foreground">
                    <span v-if="selectedTranscription">
                        Selected:
                        <strong>{{
                            selectedTranscription.name ||
                            `#${selectedTranscription.id}`
                        }}</strong>
                    </span>
                    <span v-else>No transcription selected</span>
                </div>
                <div class="flex gap-2">
                    <button
                        @click="close"
                        class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        @click="handleLink"
                        :disabled="!selectedId || linkForm.processing"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        <LinkIcon class="h-4 w-4" />
                        {{
                            linkForm.processing
                                ? 'Linking...'
                                : 'Link Transcription'
                        }}
                    </button>
                </div>
            </div>
        </template>
    </Modal>
</template>
