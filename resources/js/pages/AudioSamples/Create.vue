<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { 
    TabGroup, 
    TabList, 
    Tab, 
    TabPanels, 
    TabPanel,
    Listbox,
    ListboxButton,
    ListboxOptions,
    ListboxOption,
    Disclosure,
    DisclosureButton,
    DisclosurePanel,
} from '@headlessui/vue';
import {
    TableCellsIcon,
    DocumentArrowUpIcon,
    CheckIcon,
    ChevronUpDownIcon,
    ChevronDownIcon,
    SparklesIcon,
    CpuChipIcon,
    InformationCircleIcon,
    ArrowPathIcon,
    Cog6ToothIcon,
} from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
import ProcessorSelector from '@/components/ProcessorSelector.vue';
import { type BreadcrumbItem } from '@/types';

interface Run {
    id: number;
    batch_id: string;
    preset: string;
    status: string;
    total: number;
    completed: number;
    failed: number;
}

interface DocumentEvent {
    document: {
        id: number;
        name: string;
        clean_rate: number;
    };
    run: Run;
}

interface LlmModel {
    id: string;
    name: string;
    context_length?: number;
}

interface LlmProvider {
    name: string;
    default_model: string;
    has_credential: boolean;
    models: LlmModel[];
}

const props = defineProps<{
    presets: Record<string, { name: string; description: string; processors: string[] }>;
    processors: Record<string, string>;
    hasGoogleCredentials: boolean;
    recentRuns?: Run[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Audio Samples', href: '/audio-samples' },
    { title: 'Create', href: '/audio-samples/create' },
];

// LLM providers and models state
const llmProviders = ref<Record<string, LlmProvider>>({});
const loadingModels = ref(false);
const selectedProvider = ref('openrouter');
const selectedModel = ref('anthropic/claude-sonnet-4');
const providerModels = ref<LlmModel[]>([]);

const runs = ref<Run[]>(props.recentRuns || []);
const processedDocs = ref<DocumentEvent['document'][]>([]);

// Preset options for dropdown
const presetOptions = computed(() => 
    Object.entries(props.presets).map(([key, value]) => ({
        id: key,
        name: value.name,
        description: value.description,
        processors: value.processors,
    }))
);

// Mode options
const modeOptions = [
    { id: 'llm', name: 'AI (LLM)', description: 'Uses AI to clean documents', icon: SparklesIcon },
    { id: 'rule', name: 'Rule-based', description: 'Uses regex patterns', icon: CpuChipIcon },
];

// Sheet form (default tab)
const sheetForm = useForm({
    url: '',
    preset: 'titles_only',
    mode: 'llm',
    processors: [] as string[],
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
    sheet_name: '',
    doc_link_column: 'Doc Link',
    audio_url_column: 'Audio Link',
    row_limit: 100,
    skip_completed: true,
    output_folder_url: '',
});

// Manual create form (no cleaning - import only)
const manualForm = useForm({
    name: '',
    audio_url: '',
    audio_file: null as File | null,
    transcript_url: '',
    transcript_file: null as File | null,
});

// Toggle state for audio and transcript input types
const audioInputType = ref<'url' | 'file'>('url');
const transcriptInputType = ref<'url' | 'file'>('url');

// Computed to check if form is valid
const isManualFormValid = computed(() => {
    if (!manualForm.name) return false;
    
    // Either audio URL or file (optional)
    // Transcript is required - either URL or file
    const hasTranscript = transcriptInputType.value === 'url' 
        ? !!manualForm.transcript_url 
        : !!manualForm.transcript_file;
    
    return hasTranscript;
});

// Sync processors when preset changes
watch(() => sheetForm.preset, (newPreset) => {
    const preset = props.presets[newPreset];
    if (preset?.processors) {
        sheetForm.processors = [...preset.processors];
    }
}, { immediate: true });



// Fetch LLM providers on mount
const fetchProviders = async () => {
    try {
        const response = await fetch('/api/llm/providers');
        const data = await response.json();
        llmProviders.value = data;
        
        // Set initial models for default provider
        if (data.openrouter) {
            providerModels.value = data.openrouter.models;
        }
    } catch (error) {
        console.error('Failed to fetch LLM providers:', error);
    }
};

// Fetch models for selected provider
const fetchModelsForProvider = async (provider: string) => {
    loadingModels.value = true;
    try {
        const response = await fetch(`/api/llm/providers/${provider}/models`);
        const data = await response.json();
        providerModels.value = data.models;
        
        // Update selected model to provider default if current not available
        const modelIds = data.models.map((m: LlmModel) => m.id);
        if (!modelIds.includes(selectedModel.value)) {
            selectedModel.value = data.default;
        }
    } catch (error) {
        console.error('Failed to fetch models:', error);
        providerModels.value = llmProviders.value[provider]?.models || [];
    } finally {
        loadingModels.value = false;
    }
};

// Watch provider changes
watch(selectedProvider, (newProvider) => {
    fetchModelsForProvider(newProvider);
    sheetForm.llm_provider = newProvider;
});

watch(selectedModel, (newModel) => {
    sheetForm.llm_model = newModel;
});

const submitManual = () => {
    manualForm.post('/audio-samples', {
        forceFormData: true,
        onSuccess: () => {
            manualForm.reset();
        },
    });
};

const submitSheet = () => {
    sheetForm.post('/audio-samples/import', {
        onSuccess: () => {
            sheetForm.reset('url', 'sheet_name');
        },
    });
};

// Computed for provider options
const providerOptions = computed(() => 
    Object.entries(llmProviders.value).map(([key, value]) => ({
        id: key,
        name: value.name,
        hasCredential: value.has_credential,
    }))
);

// Get selected preset
const getSelectedPreset = (presetId: string) => 
    presetOptions.value.find(p => p.id === presetId);

// Get selected mode
const getSelectedMode = (modeId: string) =>
    modeOptions.find(m => m.id === modeId);

// Get selected model name
const getSelectedModelName = computed(() => {
    const model = providerModels.value.find(m => m.id === selectedModel.value);
    return model?.name || selectedModel.value;
});

// Echo listener for real-time updates
onMounted(() => {
    fetchProviders();
    
    runs.value.forEach((run) => {
        // @ts-ignore - Echo is global
        window.Echo?.private(`runs.${run.id}`)
            .listen('DocumentProcessed', (e: DocumentEvent) => {
                processedDocs.value.unshift(e.document);
                const runIndex = runs.value.findIndex(r => r.id === e.run.id);
                if (runIndex !== -1) {
                    runs.value[runIndex] = e.run;
                }
            })
            .listen('BatchCompleted', () => {
                window.location.reload();
            });
    });
});

onUnmounted(() => {
    runs.value.forEach((run) => {
        // @ts-ignore
        window.Echo?.leave(`runs.${run.id}`);
    });
});
</script>

<template>
    <Head title="Import Audio Samples" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Import Audio Samples</h1>
            </div>

            <!-- Tab Group -->
            <TabGroup>
                <TabList class="flex gap-1 rounded-xl bg-muted/50 p-1">
                    <Tab v-slot="{ selected }" as="template">
                        <button
                            :class="[
                                'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all',
                                selected 
                                    ? 'bg-background text-foreground shadow' 
                                    : 'text-muted-foreground hover:text-foreground hover:bg-background/50',
                                !hasGoogleCredentials && 'opacity-50 cursor-not-allowed'
                            ]"
                            :disabled="!hasGoogleCredentials"
                            v-tippy="!hasGoogleCredentials ? 'Connect Google account in Settings' : 'Import from Google Sheets'"
                        >
                            <TableCellsIcon class="w-5 h-5" />
                            Google Sheets
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
                            v-tippy="'Manually create an audio sample'"
                        >
                            <DocumentArrowUpIcon class="w-5 h-5" />
                            Manual Create
                        </button>
                    </Tab>
                </TabList>

                <TabPanels class="mt-4">
                    <!-- Google Sheets Panel (Default) -->
                    <TabPanel>
                        <div class="rounded-xl border bg-card p-6">
                            <div v-if="!hasGoogleCredentials" class="text-center py-8">
                                <TableCellsIcon class="w-12 h-12 mx-auto text-muted-foreground mb-4" />
                                <p class="text-muted-foreground mb-4">Connect your Google account to access Sheets</p>
                                <a href="/settings/credentials" class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2 font-medium text-primary-foreground hover:bg-primary/90">
                                    Go to Settings
                                </a>
                            </div>
                            <form v-else @submit.prevent="submitSheet" class="space-y-6">
                                <!-- Sheet URL -->
                                <div>
                                    <label class="block text-sm font-medium mb-2">
                                        Google Sheet URL
                                        <InformationCircleIcon 
                                            class="w-4 h-4 inline-block ml-1 text-muted-foreground cursor-help" 
                                            v-tippy="'Paste the full URL of your Google Sheet'"
                                        />
                                    </label>
                                    <input 
                                        v-model="sheetForm.url"
                                        type="url" 
                                        placeholder="https://docs.google.com/spreadsheets/d/..."
                                        class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                    />
                                    <p v-if="sheetForm.errors.url" class="mt-1 text-sm text-destructive">{{ sheetForm.errors.url }}</p>
                                </div>

                                <!-- Sheet Options -->
                                <div class="grid gap-4 md:grid-cols-3">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Sheet Name (optional)</label>
                                        <input 
                                            v-model="sheetForm.sheet_name"
                                            type="text" 
                                            placeholder="Sheet1"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Doc Link Column</label>
                                        <input 
                                            v-model="sheetForm.doc_link_column"
                                            type="text" 
                                            placeholder="Doc Link"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-2">
                                            Audio URL Column
                                            <InformationCircleIcon 
                                                class="w-4 h-4 inline-block ml-1 text-muted-foreground cursor-help" 
                                                v-tippy="'Column containing audio file URLs to download'"
                                            />
                                        </label>
                                        <input 
                                            v-model="sheetForm.audio_url_column"
                                            type="text" 
                                            placeholder="Audio Link"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        />
                                    </div>
                                </div>

                                <!-- Preset Dropdown -->
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Cleaning Preset</label>
                                        <Listbox v-model="sheetForm.preset">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border border-border bg-background py-2.5 pl-4 pr-10 text-left focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                                    <span class="block truncate">{{ getSelectedPreset(sheetForm.preset)?.name || sheetForm.preset }}</span>
                                                    <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                        <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                                    </span>
                                                </ListboxButton>
                                                <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none">
                                                    <ListboxOption
                                                        v-for="preset in presetOptions"
                                                        :key="preset.id"
                                                        :value="preset.id"
                                                        v-slot="{ active, selected }"
                                                    >
                                                        <li :class="[
                                                            'relative cursor-pointer select-none py-2 pl-10 pr-4',
                                                            active ? 'bg-primary/10 text-foreground' : 'text-foreground'
                                                        ]">
                                                            <span :class="['block truncate', selected && 'font-medium']">
                                                                {{ preset.name }}
                                                            </span>
                                                            <span class="block text-xs text-muted-foreground truncate">{{ preset.description }}</span>
                                                            <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                                <CheckIcon class="h-5 w-5" />
                                                            </span>
                                                        </li>
                                                    </ListboxOption>
                                                </ListboxOptions>
                                            </div>
                                        </Listbox>
                                    </div>

                                    <!-- Mode Dropdown -->
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Processing Mode</label>
                                        <Listbox v-model="sheetForm.mode">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border border-border bg-background py-2.5 pl-4 pr-10 text-left focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                                    <span class="flex items-center gap-2">
                                                        <component :is="getSelectedMode(sheetForm.mode)?.icon" class="w-4 h-4" />
                                                        {{ getSelectedMode(sheetForm.mode)?.name || sheetForm.mode }}
                                                    </span>
                                                    <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                        <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                                    </span>
                                                </ListboxButton>
                                                <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none">
                                                    <ListboxOption
                                                        v-for="mode in modeOptions"
                                                        :key="mode.id"
                                                        :value="mode.id"
                                                        v-slot="{ active, selected }"
                                                    >
                                                        <li :class="[
                                                            'relative cursor-pointer select-none py-2 pl-10 pr-4',
                                                            active ? 'bg-primary/10 text-foreground' : 'text-foreground'
                                                        ]">
                                                            <span class="flex items-center gap-2">
                                                                <component :is="mode.icon" class="w-4 h-4" />
                                                                <span :class="['block truncate', selected && 'font-medium']">{{ mode.name }}</span>
                                                            </span>
                                                            <span class="block text-xs text-muted-foreground ml-6">{{ mode.description }}</span>
                                                            <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                                <CheckIcon class="h-5 w-5" />
                                                            </span>
                                                        </li>
                                                    </ListboxOption>
                                                </ListboxOptions>
                                            </div>
                                        </Listbox>
                                    </div>
                                </div>

                                <!-- Advanced Options -->
                                <Disclosure v-slot="{ open }">
                                    <DisclosureButton class="flex w-full items-center justify-between rounded-lg border border-border bg-muted/30 px-4 py-2.5 text-left text-sm font-medium hover:bg-muted/50 transition-colors">
                                        <span class="flex items-center gap-2">
                                            <Cog6ToothIcon class="w-4 h-4" />
                                            Advanced Options
                                        </span>
                                        <ChevronUpDownIcon :class="['w-5 h-5 transition-transform', open && 'rotate-180']" />
                                    </DisclosureButton>
                                    <DisclosurePanel class="space-y-4 px-4 pt-4 pb-2">
                                        <!-- Processing Limits -->
                                        <div class="grid gap-4 md:grid-cols-3">
                                            <div>
                                                <label class="block text-sm font-medium mb-2">
                                                    Row Limit
                                                    <InformationCircleIcon 
                                                        class="w-4 h-4 inline-block ml-1 text-muted-foreground cursor-help" 
                                                        v-tippy="'Maximum number of rows to process'"
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
                                            <div>
                                                <label class="block text-sm font-medium mb-2">
                                                    Output Folder URL
                                                    <InformationCircleIcon 
                                                        class="w-4 h-4 inline-block ml-1 text-muted-foreground cursor-help" 
                                                        v-tippy="'Google Drive folder to save cleaned documents'"
                                                    />
                                                </label>
                                                <input 
                                                    v-model="sheetForm.output_folder_url"
                                                    type="url" 
                                                    placeholder="https://drive.google.com/drive/folders/..."
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
                                                    <span class="text-sm font-medium">Skip completed rows</span>
                                                </label>
                                            </div>
                                        </div>

                                        <!-- Processor Selection -->
                                        <div class="pt-2 border-t border-border">
                                            <ProcessorSelector
                                                v-model="sheetForm.processors"
                                                :processors="props.processors"
                                                :preset-processors="getSelectedPreset(sheetForm.preset)?.processors"
                                            />
                                        </div>
                                    </DisclosurePanel>
                                </Disclosure>

                                <!-- LLM Options (shown when mode is 'llm') -->
                                <div v-if="sheetForm.mode === 'llm'" class="grid gap-4 md:grid-cols-2 p-4 rounded-lg bg-muted/30 border border-border">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">
                                            LLM Provider
                                            <SparklesIcon class="w-4 h-4 inline-block ml-1 text-primary" />
                                        </label>
                                        <Listbox v-model="selectedProvider">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border border-border bg-background py-2.5 pl-4 pr-10 text-left focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                                    <span class="block truncate">{{ llmProviders[selectedProvider]?.name || selectedProvider }}</span>
                                                    <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                        <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                                    </span>
                                                </ListboxButton>
                                                <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none">
                                                    <ListboxOption
                                                        v-for="provider in providerOptions"
                                                        :key="provider.id"
                                                        :value="provider.id"
                                                        v-slot="{ active, selected }"
                                                    >
                                                        <li :class="[
                                                            'relative cursor-pointer select-none py-2 pl-10 pr-4',
                                                            active ? 'bg-primary/10 text-foreground' : 'text-foreground'
                                                        ]">
                                                            <span class="flex items-center gap-2">
                                                                <span :class="['block truncate', selected && 'font-medium']">{{ provider.name }}</span>
                                                                <span v-if="!provider.hasCredential" class="text-xs text-amber-500">(no key)</span>
                                                            </span>
                                                            <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                                <CheckIcon class="h-5 w-5" />
                                                            </span>
                                                        </li>
                                                    </ListboxOption>
                                                </ListboxOptions>
                                            </div>
                                        </Listbox>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium mb-2">
                                            Model
                                            <ArrowPathIcon v-if="loadingModels" class="w-4 h-4 inline-block ml-1 animate-spin" />
                                        </label>
                                        <Listbox v-model="selectedModel" :disabled="loadingModels">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border border-border bg-background py-2.5 pl-4 pr-10 text-left focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all disabled:opacity-50">
                                                    <span class="block truncate">{{ getSelectedModelName }}</span>
                                                    <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                                        <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                                                    </span>
                                                </ListboxButton>
                                                <ListboxOptions class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none">
                                                    <ListboxOption
                                                        v-for="model in providerModels"
                                                        :key="model.id"
                                                        :value="model.id"
                                                        v-slot="{ active, selected }"
                                                    >
                                                        <li :class="[
                                                            'relative cursor-pointer select-none py-2 pl-10 pr-4',
                                                            active ? 'bg-primary/10 text-foreground' : 'text-foreground'
                                                        ]">
                                                            <span :class="['block truncate', selected && 'font-medium']">{{ model.name }}</span>
                                                            <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary">
                                                                <CheckIcon class="h-5 w-5" />
                                                            </span>
                                                        </li>
                                                    </ListboxOption>
                                                </ListboxOptions>
                                            </div>
                                        </Listbox>
                                    </div>
                                </div>

                                <button 
                                    type="submit" 
                                    :disabled="sheetForm.processing || !sheetForm.url"
                                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-all"
                                >
                                    <ArrowPathIcon v-if="sheetForm.processing" class="w-4 h-4 animate-spin" />
                                    <TableCellsIcon v-else class="w-4 h-4" />
                                    {{ sheetForm.processing ? 'Importing...' : 'Import from Sheet' }}
                                </button>
                            </form>
                        </div>
                    </TabPanel>

                    <!-- Manual Create Panel -->
                    <TabPanel>
                        <div class="rounded-xl border bg-card p-6">
                            <div class="mb-6">
                                <h3 class="text-lg font-semibold mb-2">Create Audio Sample</h3>
                                <p class="text-sm text-muted-foreground">
                                    Manually create an audio sample by entering the details below. 
                                    The sample will be imported with status "Imported" and can be cleaned afterwards.
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
                                            Transcript <span class="text-destructive">*</span>
                                        </label>
                                        <div class="flex rounded-lg border border-border p-0.5">
                                            <button 
                                                type="button"
                                                @click="transcriptInputType = 'url'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    transcriptInputType === 'url' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                            >URL</button>
                                            <button 
                                                type="button"
                                                @click="transcriptInputType = 'file'"
                                                :class="[
                                                    'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                                                    transcriptInputType === 'file' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
                                                ]"
                                            >Upload</button>
                                        </div>
                                    </div>
                                    <div v-if="transcriptInputType === 'url'">
                                        <input 
                                            v-model="manualForm.transcript_url"
                                            type="url" 
                                            placeholder="https://docs.google.com/document/d/... or direct document URL"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                            :required="transcriptInputType === 'url'"
                                        />
                                        <p class="mt-1 text-xs text-muted-foreground">Link to a Google Doc or document URL</p>
                                    </div>
                                    <div v-else>
                                        <input 
                                            type="file"
                                            accept=".txt,.docx,.doc"
                                            @change="(e: Event) => manualForm.transcript_file = (e.target as HTMLInputElement).files?.[0] || null"
                                            class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-primary file:text-primary-foreground hover:file:bg-primary/90"
                                            :required="transcriptInputType === 'file'"
                                        />
                                        <p class="mt-1 text-xs text-muted-foreground">Upload a .txt, .docx, or .doc file with the transcript</p>
                                    </div>
                                    <p v-if="manualForm.errors.transcript_url" class="mt-1 text-sm text-destructive">{{ manualForm.errors.transcript_url }}</p>
                                    <p v-if="manualForm.errors.transcript_file" class="mt-1 text-sm text-destructive">{{ manualForm.errors.transcript_file }}</p>
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
                                    <p class="text-sm text-muted-foreground">
                                        After creation, you can clean the transcript from the sample detail page.
                                    </p>
                                </div>
                            </form>
                        </div>
                    </TabPanel>
                </TabPanels>
            </TabGroup>

            <!-- Active Import Runs -->
            <div v-if="runs.length > 0" class="rounded-xl border bg-card">
                <div class="border-b p-4">
                    <h2 class="font-semibold">Import Progress</h2>
                </div>
                <div class="divide-y divide-border">
                    <div v-for="run in runs" :key="run.id" class="p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium">{{ run.preset }}</span>
                            <span class="text-sm text-muted-foreground">
                                {{ run.completed + run.failed }} / {{ run.total }}
                            </span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-muted">
                            <div 
                                class="h-2 rounded-full bg-primary transition-all" 
                                :style="{ width: `${((run.completed + run.failed) / run.total) * 100}%` }"
                            ></div>
                        </div>
                        <div v-if="run.failed > 0" class="mt-1 text-sm text-destructive">
                            {{ run.failed }} failed
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recently Processed -->
            <div v-if="processedDocs.length > 0" class="rounded-xl border bg-card">
                <div class="border-b p-4">
                    <h2 class="font-semibold">Recently Processed</h2>
                </div>
                <div class="divide-y divide-border max-h-64 overflow-y-auto">
                    <div v-for="doc in processedDocs" :key="doc.id" class="flex items-center justify-between p-4">
                        <span class="font-medium">{{ doc.name }}</span>
                        <span 
                            :class="[
                                'rounded-full px-2.5 py-1 text-xs font-medium',
                                doc.clean_rate >= 90 ? 'bg-green-500/20 text-green-400' :
                                doc.clean_rate >= 75 ? 'bg-emerald-500/20 text-emerald-400' :
                                doc.clean_rate >= 50 ? 'bg-amber-500/20 text-amber-400' :
                                'bg-red-500/20 text-red-400'
                            ]"
                        >
                            {{ doc.clean_rate }}%
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
