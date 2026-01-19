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
} from '@headlessui/vue';
import {
    TableCellsIcon,
    FolderIcon,
    DocumentArrowUpIcon,
    CheckIcon,
    ChevronUpDownIcon,
    SparklesIcon,
    CpuChipIcon,
    InformationCircleIcon,
    ArrowPathIcon,
} from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
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
    { title: 'Process', href: '/process' },
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
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
    sheet_name: '',
    doc_link_column: 'Doc Link',
});

// Drive form
const driveForm = useForm({
    url: '',
    preset: 'titles_only',
    mode: 'llm',
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
});

// Upload form
const uploadForm = useForm({
    file: null as File | null,
    preset: 'titles_only',
    mode: 'llm',
    llm_provider: 'openrouter',
    llm_model: 'anthropic/claude-sonnet-4',
});

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
    
    // Sync to all forms
    sheetForm.llm_provider = newProvider;
    driveForm.llm_provider = newProvider;
    uploadForm.llm_provider = newProvider;
});

watch(selectedModel, (newModel) => {
    sheetForm.llm_model = newModel;
    driveForm.llm_model = newModel;
    uploadForm.llm_model = newModel;
});

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files?.[0]) {
        uploadForm.file = target.files[0];
    }
};

const submitUpload = () => {
    uploadForm.post('/process/upload', {
        forceFormData: true,
        onSuccess: () => {
            uploadForm.reset();
        },
    });
};

const submitDrive = () => {
    driveForm.post('/process/drive', {
        onSuccess: () => {
            driveForm.reset('url');
        },
    });
};

const submitSheet = () => {
    sheetForm.post('/process/sheet', {
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
    <Head title="Process Documents" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Process Documents</h1>
            </div>

            <!-- Tab Group - Sheets first -->
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
                            v-tippy="!hasGoogleCredentials ? 'Connect Google account in Settings' : 'Process from Google Sheets'"
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
                                    : 'text-muted-foreground hover:text-foreground hover:bg-background/50',
                                !hasGoogleCredentials && 'opacity-50 cursor-not-allowed'
                            ]"
                            :disabled="!hasGoogleCredentials"
                            v-tippy="!hasGoogleCredentials ? 'Connect Google account in Settings' : 'Process from Google Drive'"
                        >
                            <FolderIcon class="w-5 h-5" />
                            Google Drive
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
                            v-tippy="'Upload files directly'"
                        >
                            <DocumentArrowUpIcon class="w-5 h-5" />
                            Upload Files
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
                                <div class="grid gap-4 md:grid-cols-2">
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
                                    {{ sheetForm.processing ? 'Processing...' : 'Process from Sheet' }}
                                </button>
                            </form>
                        </div>
                    </TabPanel>

                    <!-- Google Drive Panel -->
                    <TabPanel>
                        <div class="rounded-xl border bg-card p-6">
                            <div v-if="!hasGoogleCredentials" class="text-center py-8">
                                <FolderIcon class="w-12 h-12 mx-auto text-muted-foreground mb-4" />
                                <p class="text-muted-foreground mb-4">Connect your Google account to access Drive folders</p>
                                <a href="/settings/credentials" class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2 font-medium text-primary-foreground hover:bg-primary/90">
                                    Go to Settings
                                </a>
                            </div>
                            <form v-else @submit.prevent="submitDrive" class="space-y-6">
                                <div>
                                    <label class="block text-sm font-medium mb-2">
                                        Drive Folder URL
                                        <InformationCircleIcon 
                                            class="w-4 h-4 inline-block ml-1 text-muted-foreground cursor-help" 
                                            v-tippy="'Paste the full URL of your Google Drive folder or document'"
                                        />
                                    </label>
                                    <input 
                                        v-model="driveForm.url"
                                        type="url" 
                                        placeholder="https://drive.google.com/drive/folders/..."
                                        class="w-full rounded-lg border border-border bg-background px-4 py-2.5 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                    />
                                    <p v-if="driveForm.errors.url" class="mt-1 text-sm text-destructive">{{ driveForm.errors.url }}</p>
                                </div>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Cleaning Preset</label>
                                        <Listbox v-model="driveForm.preset">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border border-border bg-background py-2.5 pl-4 pr-10 text-left focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                                    <span class="block truncate">{{ getSelectedPreset(driveForm.preset)?.name || driveForm.preset }}</span>
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
                                                            <span :class="['block truncate', selected && 'font-medium']">{{ preset.name }}</span>
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

                                    <div>
                                        <label class="block text-sm font-medium mb-2">Processing Mode</label>
                                        <Listbox v-model="driveForm.mode">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border border-border bg-background py-2.5 pl-4 pr-10 text-left focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                                    <span class="flex items-center gap-2">
                                                        <component :is="getSelectedMode(driveForm.mode)?.icon" class="w-4 h-4" />
                                                        {{ getSelectedMode(driveForm.mode)?.name || driveForm.mode }}
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

                                <!-- LLM Options -->
                                <div v-if="driveForm.mode === 'llm'" class="grid gap-4 md:grid-cols-2 p-4 rounded-lg bg-muted/30 border border-border">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">LLM Provider</label>
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
                                        <label class="block text-sm font-medium mb-2">Model</label>
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
                                    :disabled="driveForm.processing || !driveForm.url"
                                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-all"
                                >
                                    <ArrowPathIcon v-if="driveForm.processing" class="w-4 h-4 animate-spin" />
                                    <FolderIcon v-else class="w-4 h-4" />
                                    {{ driveForm.processing ? 'Processing...' : 'Process from Drive' }}
                                </button>
                            </form>
                        </div>
                    </TabPanel>

                    <!-- Upload Panel -->
                    <TabPanel>
                        <div class="rounded-xl border bg-card p-6">
                            <form @submit.prevent="submitUpload" class="space-y-6">
                                <div>
                                    <label class="block text-sm font-medium mb-2">Select File</label>
                                    <input 
                                        type="file" 
                                        accept=".docx,.doc,.txt"
                                        @change="handleFileChange"
                                        class="block w-full text-sm file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 file:cursor-pointer cursor-pointer"
                                    />
                                    <p v-if="uploadForm.errors.file" class="mt-1 text-sm text-destructive">{{ uploadForm.errors.file }}</p>
                                </div>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">Cleaning Preset</label>
                                        <Listbox v-model="uploadForm.preset">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border border-border bg-background py-2.5 pl-4 pr-10 text-left focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                                    <span class="block truncate">{{ getSelectedPreset(uploadForm.preset)?.name || uploadForm.preset }}</span>
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
                                                            <span :class="['block truncate', selected && 'font-medium']">{{ preset.name }}</span>
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

                                    <div>
                                        <label class="block text-sm font-medium mb-2">Processing Mode</label>
                                        <Listbox v-model="uploadForm.mode">
                                            <div class="relative">
                                                <ListboxButton class="relative w-full rounded-lg border border-border bg-background py-2.5 pl-4 pr-10 text-left focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                                    <span class="flex items-center gap-2">
                                                        <component :is="getSelectedMode(uploadForm.mode)?.icon" class="w-4 h-4" />
                                                        {{ getSelectedMode(uploadForm.mode)?.name || uploadForm.mode }}
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

                                <!-- LLM Options -->
                                <div v-if="uploadForm.mode === 'llm'" class="grid gap-4 md:grid-cols-2 p-4 rounded-lg bg-muted/30 border border-border">
                                    <div>
                                        <label class="block text-sm font-medium mb-2">LLM Provider</label>
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
                                        <label class="block text-sm font-medium mb-2">Model</label>
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
                                    :disabled="uploadForm.processing || !uploadForm.file"
                                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-all"
                                >
                                    <ArrowPathIcon v-if="uploadForm.processing" class="w-4 h-4 animate-spin" />
                                    <DocumentArrowUpIcon v-else class="w-4 h-4" />
                                    {{ uploadForm.processing ? 'Processing...' : 'Upload & Process' }}
                                </button>
                            </form>
                        </div>
                    </TabPanel>
                </TabPanels>
            </TabGroup>

            <!-- Active Processing Runs -->
            <div v-if="runs.length > 0" class="rounded-xl border bg-card">
                <div class="border-b p-4">
                    <h2 class="font-semibold">Processing Progress</h2>
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
