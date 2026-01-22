<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import {
    MagnifyingGlassIcon,
    MusicalNoteIcon,
    XMarkIcon,
    LinkIcon,
} from '@heroicons/vue/24/outline';

interface AudioSampleItem {
    id: number;
    name: string;
    status: string;
    audio_url: string | null;
    has_audio: boolean;
    created_at: string;
}

const props = defineProps<{
    isOpen: boolean;
    transcriptionId: number;
    transcriptionName?: string;
}>();

const emit = defineEmits<{
    close: [];
    linked: [audioSample: AudioSampleItem];
}>();

// Search and filter state
const searchQuery = ref('');
const loading = ref(false);
const audioSamples = ref<AudioSampleItem[]>([]);
const selectedId = ref<number | null>(null);
const linking = ref(false);

// Fetch audio samples without base transcription
const fetchAudioSamples = async () => {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (searchQuery.value) params.append('search', searchQuery.value);
        
        const response = await fetch(`/api/audio-samples/linkable?${params.toString()}`);
        const data = await response.json();
        audioSamples.value = data.audioSamples || [];
    } catch (error) {
        console.error('Failed to fetch audio samples:', error);
        audioSamples.value = [];
    } finally {
        loading.value = false;
    }
};

// Debounce search
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(fetchAudioSamples, 300);
});

// Fetch when modal opens
watch(() => props.isOpen, (isOpen) => {
    if (isOpen) {
        searchQuery.value = '';
        selectedId.value = null;
        fetchAudioSamples();
    }
});

// Handle link
const handleLink = () => {
    if (!selectedId.value) return;
    
    linking.value = true;
    router.post(`/transcriptions/${props.transcriptionId}/link`, {
        audio_sample_id: selectedId.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            const linked = audioSamples.value.find(a => a.id === selectedId.value);
            if (linked) emit('linked', linked);
            emit('close');
        },
        onFinish: () => {
            linking.value = false;
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

// Status display
const statusClass = (status: string) => {
    const map: Record<string, string> = {
        pending_base: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        pending_transcript: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
    };
    return map[status] ?? 'bg-muted text-muted-foreground';
};

const formatStatus = (status: string) => {
    const map: Record<string, string> = {
        pending_base: 'Needs Transcription',
        pending_transcript: 'Pending',
    };
    return map[status] ?? status;
};

// Selected audio sample
const selectedAudioSample = computed(() => 
    audioSamples.value.find(a => a.id === selectedId.value)
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
                                    Link to Audio Sample
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
                                    Select an audio sample without a base transcription to link 
                                    <strong>{{ transcriptionName || `Transcription #${transcriptionId}` }}</strong> to.
                                </p>

                                <!-- Search -->
                                <div class="mb-4">
                                    <div class="relative">
                                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                        <input
                                            v-model="searchQuery"
                                            type="text"
                                            placeholder="Search by name..."
                                            class="w-full rounded-lg border bg-background py-2 pl-9 pr-3 text-sm"
                                        />
                                    </div>
                                </div>

                                <!-- Audio Sample List -->
                                <div class="max-h-80 overflow-y-auto rounded-lg border">
                                    <div v-if="loading" class="flex items-center justify-center py-8">
                                        <div class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"></div>
                                    </div>

                                    <div v-else-if="audioSamples.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                                        No audio samples without base transcription found.
                                    </div>

                                    <div v-else class="divide-y">
                                        <button
                                            v-for="audioSample in audioSamples"
                                            :key="audioSample.id"
                                            @click="selectedId = audioSample.id"
                                            :class="[
                                                'w-full px-4 py-3 text-left hover:bg-muted transition-colors',
                                                selectedId === audioSample.id ? 'bg-primary/10 ring-2 ring-inset ring-primary' : '',
                                            ]"
                                        >
                                            <div class="flex items-start gap-3">
                                                <MusicalNoteIcon class="h-5 w-5 mt-0.5 text-muted-foreground shrink-0" />
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-medium truncate">
                                                            {{ audioSample.name || `Audio Sample #${audioSample.id}` }}
                                                        </span>
                                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-medium shrink-0', statusClass(audioSample.status)]">
                                                            {{ formatStatus(audioSample.status) }}
                                                        </span>
                                                    </div>
                                                    <div class="flex items-center gap-3 mt-1 text-xs text-muted-foreground">
                                                        <span v-if="audioSample.has_audio" class="text-emerald-600">Has Audio</span>
                                                        <span v-else class="text-amber-600">No Audio</span>
                                                        <span>{{ formatDate(audioSample.created_at) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="flex items-center justify-between border-t bg-muted/50 px-6 py-4">
                                <div class="text-sm text-muted-foreground">
                                    <span v-if="selectedAudioSample">
                                        Selected: <strong>{{ selectedAudioSample.name || `#${selectedAudioSample.id}` }}</strong>
                                    </span>
                                    <span v-else>No audio sample selected</span>
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
                                        :disabled="!selectedId || linking"
                                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                                    >
                                        <LinkIcon class="h-4 w-4" />
                                        {{ linking ? 'Linking...' : 'Link to Audio Sample' }}
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
