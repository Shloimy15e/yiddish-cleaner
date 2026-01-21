<script setup lang="ts">
import {
    ArrowPathIcon,
    CheckIcon,
    ChevronUpDownIcon,
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
    showRecleanForm: boolean;
    cleanForm: any;
    presetOptions: PresetOption[];
    providerOptions: ProviderOption[];
    providerModels: ModelOption[];
    selectedModelDisplay: string;
    loadingModels: boolean;
    estimatedTokens: { input: number; output: number; total: number };
    estimatedCost: { formatted: string };
}>();

const emit = defineEmits<{
    (e: 'update:showRecleanForm', value: boolean): void;
    (e: 'submit'): void;
}>();
</script>

<template>
    <div
        v-if="isVisible"
        id="reclean-step"
        class="rounded-xl border-2 border-amber-200 bg-amber-50 p-6 dark:border-amber-800 dark:bg-amber-900/20"
    >
        <div class="mb-4 flex items-center justify-between">
            <h2 class="flex items-center gap-2 text-lg font-semibold">
                <ArrowPathIcon class="h-5 w-5 text-amber-600" />
                Re-clean with Different Settings
            </h2>
            <button
                type="button"
                @click="emit('update:showRecleanForm', !showRecleanForm)"
                class="text-sm font-medium text-amber-600 hover:text-amber-700"
            >
                {{ showRecleanForm ? 'Hide Options' : 'Show Options' }}
            </button>
        </div>
        <p v-if="!showRecleanForm" class="text-sm text-muted-foreground">
            Not satisfied with the results? Try a different cleaning method or AI model.
        </p>

        <form v-if="showRecleanForm" @submit.prevent="emit('submit')" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">Mode</label>
                <div class="flex gap-2">
                    <button
                        type="button"
                        @click="cleanForm.mode = 'rule'"
                        :class="[
                            'flex-1 rounded-lg border px-3 py-2',
                            cleanForm.mode === 'rule'
                                ? 'bg-primary text-primary-foreground'
                                : '',
                        ]"
                    >
                        Rule-based
                    </button>
                    <button
                        type="button"
                        @click="cleanForm.mode = 'llm'"
                        :class="[
                            'flex-1 rounded-lg border px-3 py-2',
                            cleanForm.mode === 'llm'
                                ? 'bg-primary text-primary-foreground'
                                : '',
                        ]"
                    >
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
                            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
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

            <div v-if="cleanForm.mode === 'llm'" class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">Provider</label>
                    <Listbox v-model="cleanForm.llm_provider">
                        <div class="relative">
                            <ListboxButton
                                class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20"
                            >
                                <span class="block truncate">{{
                                    providerOptions.find((p) => p.id === cleanForm.llm_provider)?.name ||
                                    cleanForm.llm_provider
                                }}</span>
                                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                    <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
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
                        <ArrowPathIcon v-if="loadingModels" class="ml-1 inline-block h-4 w-4 animate-spin" />
                    </label>
                    <Listbox v-model="cleanForm.llm_model" :disabled="loadingModels">
                        <div class="relative">
                            <ListboxButton
                                class="relative w-full rounded-lg border border-border bg-background py-2.5 pr-10 pl-4 text-left transition-all focus:border-primary focus:ring-2 focus:ring-primary/20 disabled:opacity-50"
                            >
                                <span class="block truncate">{{ selectedModelDisplay }}</span>
                                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                                    <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
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

            <div v-if="cleanForm.mode === 'llm'" class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                <div class="flex items-start gap-3">
                    <SparklesIcon class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium text-amber-800 dark:text-amber-200">
                            Estimated Cost
                        </div>
                        <div class="mt-1 grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <span class="text-amber-600 dark:text-amber-400">Input:</span>
                                <span class="ml-1 font-mono">~{{ estimatedTokens.input.toLocaleString() }} tokens</span>
                            </div>
                            <div>
                                <span class="text-amber-600 dark:text-amber-400">Output:</span>
                                <span class="ml-1 font-mono">~{{ estimatedTokens.output.toLocaleString() }} tokens</span>
                            </div>
                            <div class="font-medium">
                                <span class="text-amber-600 dark:text-amber-400">Total:</span>
                                <span class="ml-1 font-mono text-amber-800 dark:text-amber-200">{{ estimatedCost.formatted }}</span>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                            Estimates based on {{ selectedModelDisplay }} pricing. Actual costs may vary.
                        </p>
                    </div>
                </div>
            </div>

            <p class="text-sm text-amber-600 dark:text-amber-400">
                ⚠️ Re-cleaning will overwrite the current cleaned text and remove validation status.
            </p>
            <button
                type="submit"
                :disabled="cleanForm.processing"
                class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 font-medium text-white hover:bg-amber-700 disabled:opacity-50"
            >
                <ArrowPathIcon v-if="cleanForm.processing" class="h-4 w-4 animate-spin" />
                {{ cleanForm.processing ? 'Cleaning...' : 'Re-clean Transcript' }}
            </button>
        </form>
    </div>
</template>
