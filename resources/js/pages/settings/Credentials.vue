<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { BreadcrumbItem } from '@/types';
import {
    Listbox,
    ListboxButton,
    ListboxOption,
    ListboxOptions,
} from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'API Credentials',
        href: route('settings.credentials'),
    },
];

interface ApiCredential {
    id: number;
    provider: string;
    type: string;
    default_model: string | null;
    is_active: boolean;
    created_at: string;
}

interface GoogleCredential {
    id: number;
    expires_at: string;
}

const props = defineProps<{
    apiCredentials: ApiCredential[];
    googleCredential: GoogleCredential | null;
    llmProviders: string[];
    asrProviders: string[];
}>();

const showAddForm = ref(false);
const credentialType = ref<'llm' | 'asr'>('llm');

const form = useForm({
    provider: '',
    type: 'llm' as 'llm' | 'asr',
    api_key: '',
    default_model: '',
});

const providerOptions = computed(() => {
    const providers =
        credentialType.value === 'llm'
            ? props.llmProviders
            : props.asrProviders;
    return [
        { value: '', label: 'Select provider' },
        ...providers.map((p) => ({ value: p, label: getProviderLabel(p) })),
    ];
});

const selectedProvider = computed(
    () =>
        providerOptions.value.find((o) => o.value === form.provider) ||
        providerOptions.value[0],
);

const setProvider = (option: { value: string }) => {
    form.provider = option.value;
};

const submit = () => {
    form.type = credentialType.value;
    form.post(route('settings.credentials.store'), {
        onSuccess: () => {
            form.reset();
            showAddForm.value = false;
        },
    });
};

const deleteCredential = (provider: string, type: string) => {
    if (confirm('Are you sure you want to delete this credential?')) {
        router.delete(route('settings.credentials.delete', { provider, type }));
    }
};

const disconnectGoogle = () => {
    if (confirm('Are you sure you want to disconnect your Google account?')) {
        router.delete(route('settings.google.disconnect'));
    }
};

const getProviderLabel = (provider: string) => {
    const labels: Record<string, string> = {
        openrouter: 'OpenRouter',
        openai: 'OpenAI',
        anthropic: 'Anthropic',
        google: 'Google AI',
        groq: 'Groq',
        whisper: 'OpenAI Whisper',
        google_asr: 'Google Speech-to-Text',
    };
    return labels[provider] ?? provider;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="API Credentials" />

        <h1 class="sr-only">Profile Settings</h1>

        <SettingsLayout>
            <HeadingSmall
                title="API Credentials"
                description="Configure LLM and ASR API keys for document cleaning"
            />

            <!-- Google Integration -->
            <div class="rounded-xl border bg-card p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-semibold">Google Integration</h2>
                        <p class="text-sm text-muted-foreground">
                            Connect your Google account to access Drive folders
                            and Sheets
                        </p>
                    </div>
                    <div v-if="googleCredential">
                        <div class="flex items-center gap-4">
                            <span class="text-sm text-green-600"
                                >Connected</span
                            >
                            <button
                                @click="disconnectGoogle"
                                class="rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
                            >
                                Disconnect
                            </button>
                        </div>
                    </div>
                    <a
                        v-else
                        :href="route('settings.google.redirect')"
                        class="rounded-lg bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700"
                    >
                        Connect Google
                    </a>
                </div>
            </div>

            <!-- API Credentials -->
            <div class="rounded-xl border bg-card">
                <div class="flex items-center justify-between border-b p-4">
                    <div>
                        <h2 class="font-semibold">LLM & ASR Credentials</h2>
                        <p class="text-sm text-muted-foreground">
                            Configure API keys for LLM cleaning and ASR
                            transcription
                        </p>
                    </div>
                    <button
                        @click="showAddForm = !showAddForm"
                        class="rounded-lg bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        {{ showAddForm ? 'Cancel' : 'Add Credential' }}
                    </button>
                </div>

                <!-- Add Form -->
                <div v-if="showAddForm" class="border-b bg-muted/30 p-4">
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="flex gap-4">
                            <button
                                type="button"
                                @click="credentialType = 'llm'"
                                :class="[
                                    'rounded-lg px-4 py-2 font-medium',
                                    credentialType === 'llm'
                                        ? 'bg-primary text-primary-foreground'
                                        : 'border',
                                ]"
                            >
                                LLM (Cleaning)
                            </button>
                            <button
                                type="button"
                                @click="credentialType = 'asr'"
                                :class="[
                                    'rounded-lg px-4 py-2 font-medium',
                                    credentialType === 'asr'
                                        ? 'bg-primary text-primary-foreground'
                                        : 'border',
                                ]"
                            >
                                ASR (Transcription)
                            </button>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium"
                                    >Provider</label
                                >
                                <Listbox
                                    :model-value="selectedProvider"
                                    @update:model-value="setProvider"
                                >
                                    <div class="relative">
                                        <ListboxButton
                                            class="relative w-full cursor-pointer rounded-lg border bg-background py-2 pr-10 pl-4 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                        >
                                            <span class="block truncate">{{
                                                selectedProvider.label
                                            }}</span>
                                            <span
                                                class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                                            >
                                                <ChevronUpDownIcon
                                                    class="h-5 w-5 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </ListboxButton>
                                        <transition
                                            leave-active-class="transition duration-100 ease-in"
                                            leave-from-class="opacity-100"
                                            leave-to-class="opacity-0"
                                        >
                                            <ListboxOptions
                                                class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-popover py-1 shadow-lg focus:outline-none"
                                            >
                                                <ListboxOption
                                                    v-for="option in providerOptions"
                                                    :key="option.value"
                                                    :value="option"
                                                    :disabled="
                                                        option.value === ''
                                                    "
                                                    v-slot="{
                                                        active,
                                                        selected,
                                                        disabled,
                                                    }"
                                                    as="template"
                                                >
                                                    <li
                                                        :class="[
                                                            active
                                                                ? 'bg-accent'
                                                                : '',
                                                            disabled
                                                                ? 'text-muted-foreground'
                                                                : '',
                                                            'relative cursor-pointer py-2 pr-4 pl-10 select-none',
                                                        ]"
                                                    >
                                                        <span
                                                            :class="[
                                                                selected
                                                                    ? 'font-medium'
                                                                    : 'font-normal',
                                                                'block truncate',
                                                            ]"
                                                        >
                                                            {{ option.label }}
                                                        </span>
                                                        <span
                                                            v-if="
                                                                selected &&
                                                                option.value
                                                            "
                                                            class="absolute inset-y-0 left-0 flex items-center pl-3 text-primary"
                                                        >
                                                            <CheckIcon
                                                                class="h-5 w-5"
                                                                aria-hidden="true"
                                                            />
                                                        </span>
                                                    </li>
                                                </ListboxOption>
                                            </ListboxOptions>
                                        </transition>
                                    </div>
                                </Listbox>
                                <p
                                    v-if="form.errors.provider"
                                    class="mt-1 text-sm text-red-600"
                                >
                                    {{ form.errors.provider }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium"
                                    >Default Model (optional)</label
                                >
                                <input
                                    v-model="form.default_model"
                                    type="text"
                                    placeholder="e.g., gpt-4o, claude-3-opus"
                                    class="w-full rounded-lg border bg-background p-2"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium"
                                >API Key</label
                            >
                            <input
                                v-model="form.api_key"
                                type="password"
                                placeholder="sk-..."
                                class="w-full rounded-lg border bg-background p-2"
                            />
                            <p
                                v-if="form.errors.api_key"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ form.errors.api_key }}
                            </p>
                        </div>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-lg bg-primary px-6 py-2 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{
                                form.processing
                                    ? 'Saving...'
                                    : 'Save Credential'
                            }}
                        </button>
                    </form>
                </div>

                <!-- Credentials List -->
                <div class="divide-y">
                    <div
                        v-for="cred in apiCredentials"
                        :key="`${cred.provider}-${cred.type}`"
                        class="flex items-center justify-between p-4"
                    >
                        <div class="flex items-center gap-4">
                            <div>
                                <div class="font-medium">
                                    {{ getProviderLabel(cred.provider) }}
                                </div>
                                <div class="text-sm text-muted-foreground">
                                    {{ cred.type.toUpperCase() }}
                                    <span v-if="cred.default_model">
                                        · {{ cred.default_model }}</span
                                    >
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <span
                                :class="[
                                    'rounded-full px-2 py-0.5 text-xs font-medium',
                                    cred.is_active
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'
                                        : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300',
                                ]"
                            >
                                {{ cred.is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <button
                                @click="
                                    deleteCredential(cred.provider, cred.type)
                                "
                                class="text-sm text-red-600 hover:underline"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                    <div
                        v-if="apiCredentials.length === 0"
                        class="p-8 text-center text-muted-foreground"
                    >
                        No API credentials configured yet
                    </div>
                </div>
            </div>

            <!-- Usage Info -->
            <div class="rounded-xl border bg-card p-6">
                <h2 class="mb-2 font-semibold">How Credentials Are Used</h2>
                <div
                    class="grid gap-4 text-sm text-muted-foreground md:grid-cols-2"
                >
                    <div>
                        <strong class="text-foreground">LLM Credentials</strong>
                        <p>
                            Used for AI-assisted document cleaning when using
                            the "LLM" mode. Supports OpenRouter, OpenAI,
                            Anthropic, Google AI, and Groq.
                        </p>
                    </div>
                    <div>
                        <strong class="text-foreground">ASR Credentials</strong>
                        <p>
                            Used for automatic speech recognition when
                            benchmarking transcriptions. Supports OpenAI Whisper
                            and Google Speech-to-Text.
                        </p>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
