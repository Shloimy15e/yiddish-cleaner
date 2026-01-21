<script setup lang="ts">
import {
    ArrowPathIcon,
    CheckIcon,
    ChevronUpDownIcon,
    CpuChipIcon,
    SparklesIcon,
} from '@heroicons/vue/24/outline';
import {
    Listbox,
    ListboxButton,
    ListboxOption,
    ListboxOptions,
} from '@headlessui/vue';

interface PresetOption {
    id: string;
    name: string;
    description: string;
}

interface ProviderOption {
    id: string;
    name: string;
    hasCredential: boolean;
}

interface ModelOption {
    id: string;
    name: string;
    context_length?: number;
}

const props = defineProps<{
    isVisible: boolean;
    cleanForm: any;
    presetOptions: PresetOption[];
    providerOptions: ProviderOption[];
    providerModels: ModelOption[];
    selectedModelDisplay: string;
    loadingModels: boolean;
    estimatedTokens: { input: number; output: number; total: number };
    estimatedCost: { formatted: string };
    estimatedDurationSeconds: number;
    showLlmOptions: boolean;
}>();

const emit = defineEmits<{
    (e: 'submit'): void;
    (e: 'update:showLlmOptions', value: boolean): void;
}>();
</script>

<template>
    <div
        v-if="isVisible"
        id="clean-step"
        class="rounded-xl border-2 border-blue-200 bg-blue-50 p-6 dark:border-blue-800 dark:bg-blue-900/20"
    >
        <h2 class="mb-4 flex items-center gap-2 text-lg font-semibold">
            <SparklesIcon class="h-5 w-5 text-blue-600" />
            Clean This Transcript
        </h2>
        <form @submit.prevent="emit('submit')" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">Cleaning Mode</label>
                <div class="flex gap-2">
                    <button
                        type="button"
                        @click="cleanForm.mode = 'rule'"
                        :class="[
                            'flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 font-medium transition-colors',
                            cleanForm.mode === 'rule'
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted',
                        ]"
                    >
                        <CpuChipIcon class="h-4 w-4" />
                        Rule-based
                    </button>
                    <button
                        type="button"
                        @click="cleanForm.mode = 'llm'"
                        :class="[
                            'flex flex-1 items-center justify-center gap-2 rounded-lg border px-4 py-2 font-medium transition-colors',
                            cleanForm.mode === 'llm'
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted',
                        ]"
                    >
                        <SparklesIcon class="h-4 w-4" />
                        AI (LLM)
                    </button>
                </div>
            </div>

            <div v-if="cleanForm.mode === 'rule'">
                <label class="mb-1 block text-sm font-medium">Cleaning Preset</label>
                <Listbox v-model="cleanForm.preset">
                    <div class="relative">
                        <ListboxButton
                            class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20"
                        >
                            <span class="block truncate">{{
                                presetOptions.find((p) => p.id === cleanForm.preset)?.name ||
                                cleanForm.preset
                            }}</span>
                            <span
                                class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                            >
                                <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                            </span>
                        </ListboxButton>
                        <ListboxOptions
                            class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none"
                        >
                            <ListboxOption
                                v-for="preset in presetOptions"
                                :key="preset.id"
                                :value="preset.id"
                                v-slot="{ active, selected }"
                            >
                                <li
                                    :class="[
                                        'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                        active ? 'bg-primary/10 text-foreground' : 'text-foreground',
                                    ]"
                                >
                                    <span
                                        :class="[
                                            'block truncate',
                                            selected && 'font-medium',
                                        ]"
                                    >
                                        {{ preset.name }}
                                    </span>
                                    <span class="block truncate text-xs text-muted-foreground">
                                        {{ preset.description }}
                                    </span>
                                    <span
                                        v-if="selected"
                                        class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                    >
                                        <CheckIcon class="h-5 w-5" />
                                    </span>
                                </li>
                            </ListboxOption>
                        </ListboxOptions>
                    </div>
                </Listbox>
            </div>

            <div
                v-if="cleanForm.mode === 'llm'"
                class="rounded-lg border border-border bg-muted/30 p-3"
            >
                <button
                    type="button"
                    @click="emit('update:showLlmOptions', !showLlmOptions)"
                    class="flex w-full items-center justify-between text-sm font-medium"
                >
                    <span>LLM options</span>
                    <span class="text-muted-foreground">
                        {{ showLlmOptions ? 'Hide' : 'Show' }}
                    </span>
                </button>

                <div v-if="showLlmOptions" class="mt-4 space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                LLM Provider
                                <SparklesIcon class="ml-1 inline-block h-4 w-4 text-primary" />
                            </label>
                            <Listbox v-model="cleanForm.llm_provider">
                                <div class="relative">
                                    <ListboxButton
                                        class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20"
                                    >
                                        <span class="block truncate">{{
                                            providerOptions.find((p) => p.id === cleanForm.llm_provider)?.name ||
                                            cleanForm.llm_provider
                                        }}</span>
                                        <span
                                            class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                                        >
                                            <ChevronUpDownIcon
                                                class="h-5 w-5 text-muted-foreground"
                                            />
                                        </span>
                                    </ListboxButton>
                                    <ListboxOptions
                                        class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none"
                                    >
                                        <ListboxOption
                                            v-for="provider in providerOptions"
                                            :key="provider.id"
                                            :value="provider.id"
                                            v-slot="{ active, selected }"
                                        >
                                            <li
                                                :class="[
                                                    'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                                    active ? 'bg-primary/10 text-foreground' : 'text-foreground',
                                                ]"
                                            >
                                                <span class="flex items-center gap-2">
                                                    <span
                                                        :class="[
                                                            'block truncate',
                                                            selected && 'font-medium',
                                                        ]"
                                                    >
                                                        {{ provider.name }}
                                                    </span>
                                                    <span
                                                        v-if="!provider.hasCredential"
                                                        class="text-xs text-amber-500"
                                                    >
                                                        (no key)
                                                    </span>
                                                </span>
                                                <span
                                                    v-if="selected"
                                                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                                >
                                                    <CheckIcon class="h-5 w-5" />
                                                </span>
                                            </li>
                                        </ListboxOption>
                                    </ListboxOptions>
                                </div>
                            </Listbox>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">
                                Model
                                <ArrowPathIcon
                                    v-if="loadingModels"
                                    class="ml-1 inline-block h-4 w-4 animate-spin"
                                />
                            </label>
                            <Listbox v-model="cleanForm.llm_model" :disabled="loadingModels">
                                <div class="relative">
                                    <ListboxButton
                                        class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:opacity-50"
                                    >
                                        <span class="block truncate">{{ selectedModelDisplay }}</span>
                                        <span
                                            class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                                        >
                                            <ChevronUpDownIcon
                                                class="h-5 w-5 text-muted-foreground"
                                            />
                                        </span>
                                    </ListboxButton>
                                    <ListboxOptions
                                        class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover py-1 shadow-lg focus:outline-none"
                                    >
                                        <ListboxOption
                                            v-for="model in providerModels"
                                            :key="model.id"
                                            :value="model.id"
                                            v-slot="{ active, selected }"
                                        >
                                            <li
                                                :class="[
                                                    'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                                    active ? 'bg-primary/10 text-foreground' : 'text-foreground',
                                                ]"
                                            >
                                                <span
                                                    :class="[
                                                        'block truncate',
                                                        selected && 'font-medium',
                                                    ]"
                                                >
                                                    {{ model.name }}
                                                </span>
                                                <span
                                                    v-if="model.context_length"
                                                    class="block text-xs text-muted-foreground"
                                                >
                                                    {{ (model.context_length / 1000).toFixed(0) }}k context
                                                </span>
                                                <span
                                                    v-if="selected"
                                                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                                >
                                                    <CheckIcon class="h-5 w-5" />
                                                </span>
                                            </li>
                                        </ListboxOption>
                                    </ListboxOptions>
                                </div>
                            </Listbox>
                        </div>
                    </div>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                        <div class="flex items-start gap-3">
                            <SparklesIcon class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium text-amber-800 dark:text-amber-200">
                                    Estimated Cost & Time
                                </div>
                                <div class="mt-1 grid grid-cols-3 gap-2 text-xs">
                                    <div>
                                        <span class="text-amber-600 dark:text-amber-400">Input:</span>
                                        <span class="ml-1 font-mono">
                                            ~{{ estimatedTokens.input.toLocaleString() }} tokens
                                        </span>
                                    </div>
                                    <div>
                                        <span class="text-amber-600 dark:text-amber-400">Output:</span>
                                        <span class="ml-1 font-mono">
                                            ~{{ estimatedTokens.output.toLocaleString() }} tokens
                                        </span>
                                    </div>
                                    <div class="font-medium">
                                        <span class="text-amber-600 dark:text-amber-400">Total:</span>
                                        <span class="ml-1 font-mono text-amber-800 dark:text-amber-200">
                                            {{ estimatedCost.formatted }}
                                        </span>
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                    Estimated time: ~{{ estimatedDurationSeconds }}s · {{ selectedModelDisplay }} pricing.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col items-end gap-2">
                <button
                    type="submit"
                    :disabled="cleanForm.processing"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-2 font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    <ArrowPathIcon v-if="cleanForm.processing" class="h-4 w-4 animate-spin" />
                    <SparklesIcon v-else class="h-4 w-4" />
                    {{ cleanForm.processing ? 'Cleaning...' : 'Generate Cleaned Text' }}
                </button>
                <p class="text-xs text-muted-foreground">
                    Next: review the diff and validate when ready.
                </p>
            </div>
        </form>
    </div>
</template>
