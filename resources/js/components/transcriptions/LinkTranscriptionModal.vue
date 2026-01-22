<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    Listbox,
    ListboxButton,
    ListboxOption,
    ListboxOptions,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import {
    MagnifyingGlassIcon,
    DocumentTextIcon,
    XMarkIcon,
    LinkIcon,
    CheckCircleIcon,
} from '@heroicons/vue/24/outline';
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
        if (statusFilter.value !== 'all') params.append('status', statusFilter.value);
        
        const response = await fetch(`/transcriptions/orphan-list?${params.toString()}`);
        const data = await response.json();
        transcriptions.value = Array.isArray(data) ? data : (data.transcriptions || []);
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
watch(() => props.isOpen, (isOpen) => {
    if (isOpen) {
        searchQuery.value = '';
        statusFilter.value = 'all';
        selectedId.value = null;
        fetchTranscriptions();
    }
});

// Handle link
const handleLink = () => {
    if (!selectedId.value) return;
    
    router.post(`/transcriptions/${selectedId.value}/link`, {
        audio_sample_id: props.audioSampleId,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            const linked = transcriptions.value.find(t => t.id === selectedId.value);
            if (linked) emit('linked', linked);
            emit('close');
        },
    });
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
    transcriptions.value.find(t => t.id === selectedId.value)
);
</script>

<template>
    <TransitionRoot appear :show="isOpen" as="template">
        <Dialog as="div" @close="close" class="relative z-50">
            <TransitionChild
                as="template"
                enter="duration-300 ease-out"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="duration-200 ease-in"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-black/40" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <TransitionChild
                        as="template"
                        enter="duration-300 ease-out"
                        enter-from="opacity-0 scale-95"
                        enter-to="opacity-100 scale-100"
                        leave="duration-200 ease-in"
                        leave-from="opacity-100 scale-100"
                        leave-to="opacity-0 scale-95"
                    >
                        <DialogPanel class="w-full max-w-2xl transform overflow-hidden rounded-2xl bg-background border shadow-xl transition-all">
                            <!-- Header -->
                            <div class="flex items-center justify-between border-b px-6 py-4">
                                <DialogTitle class="text-lg font-semibold">
                                    Link Base Transcription
                                </DialogTitle>
                                <button
                                    @click="close"
                                    class="rounded-lg p-1 hover:bg-muted"
                                >
                                    <XMarkIcon class="h-5 w-5" />
                                </button>
                            </div>

                            <!-- Content -->
                            <div class="p-6">
                                <p class="text-sm text-muted-foreground mb-4">
                                    Select an orphan base transcription to link to 
                                    <strong>{{ audioSampleName || `Audio Sample #${audioSampleId}` }}</strong>.
                                </p>

                                <!-- Search and Filter -->
                                <div class="flex gap-3 mb-4">
                                    <div class="relative flex-1">
                                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                        <input
                                            v-model="searchQuery"
                                            type="text"
                                            placeholder="Search by name..."
                                            class="w-full rounded-lg border bg-background py-2 pl-9 pr-3 text-sm"
                                        />
                                    </div>
                                    <Listbox v-model="statusFilter">
                                        <div class="relative w-40">
                                            <ListboxButton class="relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm">
                                                <span>{{ statusFilter === 'all' ? 'All Status' : statusFilter === 'validated' ? 'Validated' : 'Unvalidated' }}</span>
                                                <span class="absolute inset-y-0 right-0 flex items-center pr-2">
                                                    <ChevronUpDownIcon class="h-4 w-4 text-muted-foreground" />
                                                </span>
                                            </ListboxButton>
                                            <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg">
                                                <ListboxOption value="all" v-slot="{ active, selected }">
                                                    <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm', active ? 'bg-muted' : '']">
                                                        <span :class="['block', selected ? 'font-medium' : '']">All Status</span>
                                                        <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                            <CheckIcon class="h-4 w-4" />
                                                        </span>
                                                    </li>
                                                </ListboxOption>
                                                <ListboxOption value="validated" v-slot="{ active, selected }">
                                                    <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm', active ? 'bg-muted' : '']">
                                                        <span :class="['block', selected ? 'font-medium' : '']">Validated</span>
                                                        <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                            <CheckIcon class="h-4 w-4" />
                                                        </span>
                                                    </li>
                                                </ListboxOption>
                                                <ListboxOption value="unvalidated" v-slot="{ active, selected }">
                                                    <li :class="['relative cursor-pointer py-2 pl-10 pr-4 text-sm', active ? 'bg-muted' : '']">
                                                        <span :class="['block', selected ? 'font-medium' : '']">Unvalidated</span>
                                                        <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                            <CheckIcon class="h-4 w-4" />
                                                        </span>
                                                    </li>
                                                </ListboxOption>
                                            </ListboxOptions>
                                        </div>
                                    </Listbox>
                                </div>

                                <!-- Transcription List -->
                                <div class="max-h-80 overflow-y-auto rounded-lg border">
                                    <div v-if="loading" class="flex items-center justify-center py-8">
                                        <div class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"></div>
                                    </div>

                                    <div v-else-if="transcriptions.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                                        No orphan transcriptions found.
                                    </div>

                                    <div v-else class="divide-y">
                                        <button
                                            v-for="transcription in transcriptions"
                                            :key="transcription.id"
                                            @click="selectedId = transcription.id"
                                            :class="[
                                                'w-full px-4 py-3 text-left hover:bg-muted transition-colors',
                                                selectedId === transcription.id ? 'bg-primary/10 ring-2 ring-inset ring-primary' : '',
                                            ]"
                                        >
                                            <div class="flex items-start gap-3">
                                                <DocumentTextIcon class="h-5 w-5 mt-0.5 text-muted-foreground shrink-0" />
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-medium truncate">
                                                            {{ transcription.name || `Transcription #${transcription.id}` }}
                                                        </span>
                                                        <CheckCircleIcon
                                                            v-if="transcription.validated_at"
                                                            class="h-4 w-4 text-emerald-600 shrink-0"
                                                        />
                                                    </div>
                                                    <div class="flex items-center gap-3 mt-1 text-xs text-muted-foreground">
                                                        <span v-if="transcription.clean_rate !== null" class="font-mono">
                                                            {{ transcription.clean_rate }}% clean
                                                        </span>
                                                        <span>{{ formatDate(transcription.created_at) }}</span>
                                                    </div>
                                                    <p
                                                        v-if="transcription.text_clean"
                                                        class="mt-1 text-xs text-muted-foreground line-clamp-2"
                                                        dir="auto"
                                                    >
                                                        {{ transcription.text_clean.slice(0, 150) }}{{ transcription.text_clean.length > 150 ? '...' : '' }}
                                                    </p>
                                                </div>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="flex items-center justify-between border-t bg-muted/50 px-6 py-4">
                                <div class="text-sm text-muted-foreground">
                                    <span v-if="selectedTranscription">
                                        Selected: <strong>{{ selectedTranscription.name || `#${selectedTranscription.id}` }}</strong>
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
                                        {{ linkForm.processing ? 'Linking...' : 'Link Transcription' }}
                                    </button>
                                </div>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
