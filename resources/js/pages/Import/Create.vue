<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import { 
    TabGroup, 
    TabList, 
    Tab, 
    TabPanels, 
    TabPanel,
} from '@headlessui/vue';
import {
    TableCellsIcon,
    DocumentArrowUpIcon,
    InformationCircleIcon,
    ArrowPathIcon,
    Cog6ToothIcon,
    ArrowUpTrayIcon,
    LinkIcon,
    DocumentTextIcon,
    MagnifyingGlassIcon,
    ClipboardDocumentIcon,
} from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { BaseTranscription } from '@/types/transcriptions';
import { formatDate } from '@/lib/date';

const props = defineProps<{
    hasGoogleCredentials: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Imports', href: '/imports' },
    { title: 'New Import', href: '/imports/create' },
];

// Toggle state for sheet input type (URL vs file upload)
// Default to 'file' if no Google credentials
const sheetInputType = ref<'url' | 'file'>(props.hasGoogleCredentials ? 'url' : 'file');

// Sheet form - import only, no cleaning
const sheetForm = useForm({
    url: '',
    file: null as File | null,
    sheet_name: '',
    doc_link_column: '',
    audio_url_column: '',
    row_limit: 100,
    skip_completed: true,
});

// Manual create form (import only) - now with optional transcript
const manualForm = useForm({
    name: '',
    audio_url: '',
    audio_file: null as File | null,
    transcript_url: '',
    transcript_file: null as File | null,
    transcript_text: '',
    base_transcription_id: null as number | null,
});

// Toggle state for audio and transcript input types
// Default to file if no Google credentials
const audioInputType = ref<'url' | 'file'>(props.hasGoogleCredentials ? 'url' : 'file');
const transcriptInputType = ref<'url' | 'file' | 'paste' | 'link'>('file');

// State for linking existing transcription
const showTranscriptionSearch = ref(false);
const searchQuery = ref('');
const searchResults = ref<BaseTranscription[]>([]);
const searchLoading = ref(false);
const selectedTranscription = ref<BaseTranscription | null>(null);

// Search for orphan transcriptions
const searchTranscriptions = async () => {
    if (!searchQuery.value.trim()) {
        searchResults.value = [];
        return;
    }
    searchLoading.value = true;
    try {
        const params = new URLSearchParams({ search: searchQuery.value });
        const response = await fetch(`/transcriptions/orphan-list?${params}`);
        const data = await response.json();
        searchResults.value = Array.isArray(data) ? data : (data.transcriptions || []);
    } catch (error) {
        console.error('Failed to search transcriptions:', error);
        searchResults.value = [];
    } finally {
        searchLoading.value = false;
    }
};

// Debounce search
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
watch(searchQuery, () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(searchTranscriptions, 300);
});

// Select a transcription
const selectTranscription = (transcription: BaseTranscription) => {
    selectedTranscription.value = transcription;
    manualForm.base_transcription_id = transcription.id;
    showTranscriptionSearch.value = false;
};

// Clear selected transcription
const clearSelectedTranscription = () => {
    selectedTranscription.value = null;
    manualForm.base_transcription_id = null;
};

// Watch transcript input type changes
watch(transcriptInputType, (type) => {
    if (type !== 'link') {
        clearSelectedTranscription();
    }
    if (type === 'link') {
        manualForm.transcript_url = '';
        manualForm.transcript_file = null;
        manualForm.transcript_text = '';
    }
    if (type === 'paste') {
        manualForm.transcript_url = '';
        manualForm.transcript_file = null;
    }
    if (type === 'file' || type === 'url') {
        manualForm.transcript_text = '';
    }
});

// Computed to check if form is valid
// Either audio OR transcript must be provided
const isManualFormValid = computed(() => {
    if (!manualForm.name) return false;
    
    // Check for audio
    const hasAudio = audioInputType.value === 'url'
        ? !!manualForm.audio_url
        : !!manualForm.audio_file;
    
    // Check for transcript (URL, file, paste, or linked)
    let hasTranscript = false;
    if (transcriptInputType.value === 'url') {
        hasTranscript = !!manualForm.transcript_url;
    } else if (transcriptInputType.value === 'file') {
        hasTranscript = !!manualForm.transcript_file;
    } else if (transcriptInputType.value === 'paste') {
        hasTranscript = !!manualForm.transcript_text?.trim();
    } else if (transcriptInputType.value === 'link') {
        hasTranscript = !!manualForm.base_transcription_id;
    }
    
    // At least one must be provided
    return hasAudio || hasTranscript;
});

// Check if sheet form is valid
const isSheetFormValid = computed(() => {
    // Must have source (URL or file)
    const hasSource = sheetInputType.value === 'url'
        ? (props.hasGoogleCredentials && !!sheetForm.url)
        : !!sheetForm.file;
    
    // At least one column must be specified
    const hasColumn = !!sheetForm.doc_link_column?.trim() || !!sheetForm.audio_url_column?.trim();
    
    return hasSource && hasColumn;
});

const submitManual = () => {
    manualForm.post('/audio-samples', {
        forceFormData: true,
        onSuccess: () => {
            manualForm.reset();
            selectedTranscription.value = null;
        },
    });
};

const submitSheet = () => {
    sheetForm.post('/imports', {
        forceFormData: true,
        onSuccess: () => {
            sheetForm.reset('url', 'file', 'sheet_name');
        },
    });
};

</script>

<template>
    <Head title="Import" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Import Data</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        Import audio samples and base transcriptions from spreadsheets or manually upload files.
                    </p>
                </div>
            </div>

            <!-- Tab Group -->
            <TabGroup>
                <TabList class="grid gap-1 rounded-xl bg-muted/50 p-1 sm:flex sm:gap-1">
                    <Tab v-slot="{ selected }" as="template">
                        <button
                            :class="[
                                'flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all',
                                selected 
                                    ? 'bg-background text-foreground shadow' 
                                    : 'text-muted-foreground hover:text-foreground hover:bg-background/50'
                            ]"
                            v-tippy="'Batch import from Google Sheets or spreadsheet files'"
                        >
                            <TableCellsIcon class="w-5 h-5" />
                            Batch Import
                        </button>
                    </Tab>
                    <Tab v-slot="{ selected }" as="template">
                        <button
                            :class="[
                                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all',
                                selected 
                                    ? 'bg-background text-foreground shadow' 
                                    : 'text-muted-foreground hover:text-foreground hover:bg-background/50'
                            ]"
                            v-tippy="'Upload a single audio sample with optional transcript'"
                        >
                            <DocumentArrowUpIcon class="w-5 h-5" />
                            Single Upload
                        </button>
                    </Tab>
                </TabList>

                <TabPanels class="mt-4">
                    <!-- Spreadsheet Panel -->
                    <TabPanel>
                        <div class="rounded-xl border bg-card p-4 sm:p-6">
                            <div class="mb-6">
                                <h3 class="text-lg font-semibold mb-2">Batch Import from Spreadsheet</h3>
                                <p class="text-sm text-muted-foreground">
                                    Import multiple audio samples and transcriptions from a Google Sheet or spreadsheet file.
                                    Each row creates an audio sample linked to a base transcription.
                                </p>
                            </div>
                            <form @submit.prevent="submitSheet" class="space-y-6">
                                <!-- Sheet Source -->
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-sm font-medium">
                                            Source
                                            <InformationCircleIcon 
                                                class="w-4 h-4 inline-block ml-1 text-muted-foreground cursor-help" 
                                                v-tippy="sheetInputType === 'url' ? 'Paste the full URL of your Google Sheet' : 'Upload a CSV or Excel file'"
                                            />
                                        </label>
                                        <div class="flex rounded-lg border border-border p-0.5">
                                            <button 
                                                type="button"
                                                @click="sheetInputType = 'url'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    sheetInputType === 'url' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                                v-tippy="'Import from Google Sheets URL'"
                                            >URL</button>
                                            <button 
                                                type="button"
                                                @click="sheetInputType = 'file'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    sheetInputType === 'file' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                            >Upload</button>
                                        </div>
                                    </div>
                                    <div v-if="sheetInputType === 'url'">
                                        <div v-if="!hasGoogleCredentials" class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-4">
                                            <p class="text-sm text-amber-600 dark:text-amber-400 mb-2">
                                                Google account required to import from URLs.
                                            </p>
                                            <a href="/settings/credentials" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                                                Connect in Settings →
                                            </a>
                                        </div>
                                        <div v-else>
                                            <input 
                                                v-model="sheetForm.url"
                                                type="url" 
                                                placeholder="https://docs.google.com/spreadsheets/d/..."
                                                class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                            />
                                            <p class="mt-1 text-xs text-muted-foreground">Paste a Google Sheets URL</p>
                                        </div>
                                    </div>
                                    <div v-else>
                                        <input 
                                            type="file"
                                            accept=".csv,.xlsx,.xls"
                                            @change="(e: Event) => sheetForm.file = (e.target as HTMLInputElement).files?.[0] || null"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-primary file:text-primary-foreground hover:file:bg-primary/90"
                                        />
                                        <p class="mt-1 text-xs text-muted-foreground">Upload a CSV or Excel file (.csv, .xlsx, .xls)</p>
                                    </div>
                                    <p v-if="sheetForm.errors.url" class="mt-1 text-sm text-destructive">{{ sheetForm.errors.url }}</p>
                                    <p v-if="sheetForm.errors.file" class="mt-1 text-sm text-destructive">{{ sheetForm.errors.file }}</p>
                                </div>

                                <!-- Column Mapping -->
                                <div class="grid gap-4 md:grid-cols-3">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Sheet Name (optional)</label>
                                        <input 
                                            v-model="sheetForm.sheet_name"
                                            type="text" 
                                            placeholder="Leave blank to use first sheet"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-2">
                                            Doc Link Column
                                            <span class="text-muted-foreground font-normal text-xs">(optional)</span>
                                            <InformationCircleIcon 
                                                class="w-4 h-4 inline-block ml-1 text-muted-foreground cursor-help" 
                                                v-tippy="'Column containing Google Doc links with transcripts'"
                                            />
                                        </label>
                                        <input 
                                            v-model="sheetForm.doc_link_column"
                                            type="text" 
                                            placeholder="e.g. Doc Link"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-2">
                                            Audio URL Column
                                            <span class="text-muted-foreground font-normal text-xs">(optional)</span>
                                            <InformationCircleIcon 
                                                class="w-4 h-4 inline-block ml-1 text-muted-foreground cursor-help" 
                                                v-tippy="'Column containing audio file URLs to download'"
                                            />
                                        </label>
                                        <input 
                                            v-model="sheetForm.audio_url_column"
                                            type="text" 
                                            placeholder="e.g. Audio Link"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        />
                                    </div>
                                </div>

                                <!-- Instructions -->
                                <div class="rounded-lg border border-border bg-muted/30 p-4">
                                    <p class="text-sm text-muted-foreground">
                                        Specify at least one column name. You can import transcripts only, audio only, or both together. Column names are case-insensitive.
                                    </p>
                                </div>

                                <!-- Advanced Options -->
                                <div class="rounded-lg border border-border bg-muted/30 px-4 py-4">
                                    <div class="flex items-center gap-2 text-sm font-medium mb-4">
                                        <Cog6ToothIcon class="w-4 h-4" />
                                        Advanced Options
                                    </div>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label class="block text-sm font-medium mb-2">
                                                Row Limit
                                                <InformationCircleIcon 
                                                    class="w-4 h-4 inline-block ml-1 text-muted-foreground cursor-help" 
                                                    v-tippy="'Maximum number of rows to import'"
                                                />
                                            </label>
                                            <input 
                                                v-model.number="sheetForm.row_limit"
                                                type="number" 
                                                min="1" 
                                                max="1000"
                                                class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                            />
                                        </div>
                                        <div class="flex items-end">
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input 
                                                    v-model="sheetForm.skip_completed"
                                                    type="checkbox" 
                                                    class="h-4 w-4 rounded border-border text-primary focus:ring-primary/20"
                                                />
                                                <span class="text-sm font-medium">Skip already imported rows</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Info Box -->
                                <div class="rounded-lg bg-blue-500/10 border border-blue-500/20 p-4">
                                    <div class="flex gap-3">
                                        <InformationCircleIcon class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" />
                                        <div class="text-sm">
                                            <p class="font-medium text-blue-500">How Batch Import Works</p>
                                            <ul class="text-muted-foreground mt-1 space-y-1 list-disc list-inside">
                                                <li>Each row creates an <strong>Audio Sample</strong> + linked <strong>Base Transcription</strong></li>
                                                <li>Doc Link column should contain Google Doc URLs with transcript text</li>
                                                <li>Audio URL column (optional) downloads and attaches audio files</li>
                                                <li>After import, clean transcriptions from the Transcriptions page</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <button 
                                    type="submit" 
                                    :disabled="sheetForm.processing || !isSheetFormValid"
                                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-all"
                                >
                                    <ArrowPathIcon v-if="sheetForm.processing" class="w-4 h-4 animate-spin" />
                                    <ArrowUpTrayIcon v-else-if="sheetInputType === 'file'" class="w-4 h-4" />
                                    <TableCellsIcon v-else class="w-4 h-4" />
                                    {{ sheetForm.processing ? 'Importing...' : (sheetInputType === 'file' ? 'Upload & Import' : 'Import from Sheet') }}
                                </button>
                            </form>
                        </div>
                    </TabPanel>

                    <!-- Manual Create Panel -->
                    <TabPanel>
                        <div class="rounded-xl border bg-card p-4 sm:p-6">
                            <div class="mb-6">
                                <h3 class="text-lg font-semibold mb-2">Single Upload</h3>
                                <p class="text-sm text-muted-foreground">
                                    Upload a single audio file with an optional base transcription.
                                    You can also link to an existing orphan transcription.
                                </p>
                            </div>
                            <form @submit.prevent="submitManual" class="space-y-6">
                                <!-- Sample Name -->
                                <div>
                                    <label class="block text-sm font-medium mb-2">
                                        Sample Name <span class="text-destructive">*</span>
                                    </label>
                                    <input 
                                        v-model="manualForm.name"
                                        type="text" 
                                        placeholder="Enter a descriptive name for this sample"
                                        class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        required
                                    />
                                    <p v-if="manualForm.errors.name" class="mt-1 text-sm text-destructive">{{ manualForm.errors.name }}</p>
                                </div>

                                <!-- Audio Source -->
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-sm font-medium">
                                            Audio Source
                                            <span class="text-muted-foreground font-normal">(optional)</span>
                                        </label>
                                        <div class="flex rounded-lg border border-border p-0.5">
                                            <button 
                                                type="button"
                                                @click="audioInputType = 'url'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    audioInputType === 'url' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                                :disabled="!hasGoogleCredentials"
                                                v-tippy="!hasGoogleCredentials ? 'Connect Google account to use URL import' : 'Use URL'"
                                            >URL</button>
                                            <button 
                                                type="button"
                                                @click="audioInputType = 'file'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    audioInputType === 'file' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                            >Upload</button>
                                        </div>
                                    </div>
                                    <div v-if="!hasGoogleCredentials" class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 mb-2">
                                        <p class="text-xs text-amber-600 dark:text-amber-400">
                                            Connect your Google account to enable URL imports. 
                                            <a href="/settings/credentials" class="inline-flex items-center font-medium text-primary hover:underline">
                                                Connect in Settings →
                                            </a>
                                        </p>
                                    </div>
                                    <div v-if="audioInputType === 'url'">
                                        <input 
                                            v-model="manualForm.audio_url"
                                            type="url" 
                                            placeholder="https://drive.google.com/... or direct audio URL"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        />
                                        <p class="mt-1 text-xs text-muted-foreground">Link to the audio file (Google Drive, direct URL, etc.)</p>
                                    </div>
                                    <div v-else>
                                        <input 
                                            type="file"
                                            accept="audio/*,.mp3,.wav,.ogg,.m4a,.flac"
                                            @change="(e: Event) => manualForm.audio_file = (e.target as HTMLInputElement).files?.[0] || null"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-primary file:text-primary-foreground hover:file:bg-primary/90"
                                        />
                                        <p class="mt-1 text-xs text-muted-foreground">Upload an audio file (.mp3, .wav, .ogg, .m4a, .flac)</p>
                                    </div>
                                    <p v-if="manualForm.errors.audio_url" class="mt-1 text-sm text-destructive">{{ manualForm.errors.audio_url }}</p>
                                    <p v-if="manualForm.errors.audio_file" class="mt-1 text-sm text-destructive">{{ manualForm.errors.audio_file }}</p>
                                </div>

                                <!-- Transcript Source -->
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="text-sm font-medium">
                                            Base Transcription
                                            <span class="text-muted-foreground font-normal">(optional if audio provided)</span>
                                        </label>
                                        <div class="flex rounded-lg border border-border p-0.5">
                                            <button 
                                                type="button"
                                                @click="transcriptInputType = 'file'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    transcriptInputType === 'file' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                            >Upload</button>
                                            <button 
                                                type="button"
                                                @click="transcriptInputType = 'paste'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    transcriptInputType === 'paste' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                                v-tippy="'Paste transcript text directly'"
                                            >Paste</button>
                                            <button 
                                                type="button"
                                                @click="transcriptInputType = 'url'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    transcriptInputType === 'url' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                                :disabled="!hasGoogleCredentials"
                                                v-tippy="!hasGoogleCredentials ? 'Connect Google account to use URL import' : 'Use URL'"
                                            >URL</button>
                                            <button 
                                                type="button"
                                                @click="transcriptInputType = 'link'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    transcriptInputType === 'link' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                                v-tippy="'Link an existing orphan transcription'"
                                            >Link</button>
                                        </div>
                                    </div>

                                    <!-- File Upload -->
                                    <div v-if="transcriptInputType === 'file'">
                                        <input 
                                            type="file"
                                            accept=".txt,.docx,.doc"
                                            @change="(e: Event) => manualForm.transcript_file = (e.target as HTMLInputElement).files?.[0] || null"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-primary file:text-primary-foreground hover:file:bg-primary/90"
                                        />
                                        <p class="mt-1 text-xs text-muted-foreground">Upload a .txt, .docx, or .doc file with the transcript</p>
                                    </div>

                                    <!-- Paste Input -->
                                    <div v-else-if="transcriptInputType === 'paste'">
                                        <textarea 
                                            v-model="manualForm.transcript_text"
                                            rows="6"
                                            placeholder="Paste your transcript text here..."
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all resize-y min-h-[120px]"
                                        ></textarea>
                                        <p class="mt-1 text-xs text-muted-foreground">Paste the transcript text directly</p>
                                        <p v-if="manualForm.errors.transcript_text" class="mt-1 text-sm text-destructive">{{ manualForm.errors.transcript_text }}</p>
                                    </div>

                                    <!-- URL Input -->
                                    <div v-else-if="transcriptInputType === 'url'">
                                        <div v-if="!hasGoogleCredentials" class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 mb-2">
                                            <p class="text-xs text-amber-600 dark:text-amber-400">
                                                Connect your Google account to enable URL imports. 
                                                <a href="/settings/credentials" class="inline-flex items-center font-medium text-primary hover:underline">
                                                    Connect in Settings →
                                                </a>
                                            </p>
                                        </div>
                                        <input 
                                            v-model="manualForm.transcript_url"
                                            type="url" 
                                            placeholder="https://docs.google.com/document/d/... or direct document URL"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        />
                                        <p class="mt-1 text-xs text-muted-foreground">Link to a Google Doc or document URL</p>
                                    </div>

                                    <!-- Link Existing Transcription -->
                                    <div v-else-if="transcriptInputType === 'link'">
                                        <!-- Selected Transcription -->
                                        <div v-if="selectedTranscription" class="rounded-lg border bg-muted/30 p-4">
                                            <div class="flex items-start justify-between">
                                                <div class="flex items-start gap-3">
                                                    <DocumentTextIcon class="h-5 w-5 text-muted-foreground shrink-0 mt-0.5" />
                                                    <div>
                                                        <p class="font-medium">{{ selectedTranscription.name || `Transcription #${selectedTranscription.id}` }}</p>
                                                        <p class="text-xs text-muted-foreground mt-1">
                                                            <span v-if="selectedTranscription.validated_at" class="text-emerald-600">✓ Validated</span>
                                                            <span v-else-if="selectedTranscription.text_clean" class="text-amber-600">Cleaned</span>
                                                            <span v-else>Pending</span>
                                                            · Created {{ formatDate(selectedTranscription.created_at) }}
                                                        </p>
                                                    </div>
                                                </div>
                                                <button 
                                                    type="button"
                                                    @click="clearSelectedTranscription"
                                                    class="text-sm text-muted-foreground hover:text-foreground"
                                                >
                                                    Change
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Search Input -->
                                        <div v-else>
                                            <div class="relative">
                                                <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                                <input 
                                                    v-model="searchQuery"
                                                    type="text"
                                                    placeholder="Search orphan transcriptions..."
                                                    class="w-full rounded-lg border border-border bg-background py-2.5 pl-10 pr-4 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                                    @focus="showTranscriptionSearch = true"
                                                />
                                            </div>

                                            <!-- Search Results Dropdown -->
                                            <div v-if="showTranscriptionSearch && searchQuery" class="mt-2 max-h-60 overflow-y-auto rounded-lg border bg-background shadow-lg">
                                                <div v-if="searchLoading" class="p-4 text-center text-sm text-muted-foreground">
                                                    Searching...
                                                </div>
                                                <div v-else-if="searchResults.length === 0" class="p-4 text-center text-sm text-muted-foreground">
                                                    No orphan transcriptions found
                                                </div>
                                                <button
                                                    v-else
                                                    v-for="transcription in searchResults"
                                                    :key="transcription.id"
                                                    type="button"
                                                    @click="selectTranscription(transcription)"
                                                    class="w-full px-4 py-3 text-left hover:bg-muted transition-colors border-b last:border-b-0"
                                                >
                                                    <p class="font-medium">{{ transcription.name || `Transcription #${transcription.id}` }}</p>
                                                    <p class="text-xs text-muted-foreground mt-1">
                                                        <span v-if="transcription.validated_at" class="text-emerald-600">✓ Validated</span>
                                                        <span v-else-if="transcription.text_clean" class="text-amber-600">Cleaned</span>
                                                        <span v-else>Pending</span>
                                                        · {{ formatDate(transcription.created_at) }}
                                                    </p>
                                                </button>
                                            </div>
                                            <p class="mt-1 text-xs text-muted-foreground">
                                                Search for an existing orphan transcription to link
                                            </p>
                                        </div>
                                    </div>

                                    <p v-if="manualForm.errors.transcript_url" class="mt-1 text-sm text-destructive">{{ manualForm.errors.transcript_url }}</p>
                                    <p v-if="manualForm.errors.transcript_file" class="mt-1 text-sm text-destructive">{{ manualForm.errors.transcript_file }}</p>
                                    <p v-if="manualForm.errors.base_transcription_id" class="mt-1 text-sm text-destructive">{{ manualForm.errors.base_transcription_id }}</p>
                                </div>

                                <!-- Info about requirements -->
                                <div class="rounded-lg bg-blue-500/10 border border-blue-500/20 p-4">
                                    <div class="flex gap-3">
                                        <InformationCircleIcon class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" />
                                        <div class="text-sm">
                                            <p class="font-medium text-blue-500">Flexible Upload Options</p>
                                            <ul class="text-muted-foreground mt-1 space-y-1 list-disc list-inside">
                                                <li>Upload <strong>audio only</strong> — add transcription later</li>
                                                <li>Upload <strong>transcription only</strong> — add audio later</li>
                                                <li>Upload <strong>both together</strong> — ready for cleaning</li>
                                                <li>Or <strong>link an existing</strong> orphan transcription to this sample</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-4">
                                    <button 
                                        type="submit" 
                                        :disabled="manualForm.processing || !isManualFormValid"
                                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-all"
                                    >
                                        <ArrowPathIcon v-if="manualForm.processing" class="w-4 h-4 animate-spin" />
                                        <DocumentArrowUpIcon v-else class="w-4 h-4" />
                                        {{ manualForm.processing ? 'Creating...' : 'Create Sample' }}
                                    </button>
                                    <p v-if="!isManualFormValid" class="text-sm text-muted-foreground">
                                        Provide at least an audio file or a base transcription.
                                    </p>
                                </div>
                            </form>
                        </div>
                    </TabPanel>
                </TabPanels>
            </TabGroup>
        </div>
    </AppLayout>
</template>
