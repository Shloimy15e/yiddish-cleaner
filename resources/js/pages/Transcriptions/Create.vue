<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import {
    DocumentArrowUpIcon,
    DocumentTextIcon,
    LinkIcon,
    ArrowPathIcon,
} from '@heroicons/vue/24/outline';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { Preset } from '@/types/audio-samples';

const props = defineProps<{
    presets: Record<string, Preset>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Base Transcriptions', href: '/transcriptions' },
    { title: 'Create', href: '/transcriptions/create' },
];

// Source type: file upload, paste text, or URL
const sourceType = ref<'file' | 'text' | 'url'>('file');

const form = useForm({
    name: '',
    source_type: 'file' as 'file' | 'text' | 'url',
    file: null as File | null,
    text: '',
    url: '',
    audio_sample_id: null as number | null,
});

// Computed validation
const isFormValid = computed(() => {
    if (!form.name) return false;
    
    if (sourceType.value === 'file') {
        return !!form.file;
    } else if (sourceType.value === 'text') {
        return !!form.text.trim();
    } else if (sourceType.value === 'url') {
        return !!form.url.trim();
    }
    return false;
});

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        form.file = target.files[0];
        // Auto-fill name from filename if empty
        if (!form.name && form.file) {
            form.name = form.file.name.replace(/\.[^.]+$/, '');
        }
    }
};

const submit = () => {
    form.source_type = sourceType.value;
    form.post('/transcriptions', {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <Head title="Create Base Transcription" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full w-full max-w-3xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="flex flex-col gap-2">
                <h1 class="text-2xl font-bold">Create Base Transcription</h1>
                <p class="text-sm text-muted-foreground">
                    Upload or paste a reference transcript. It can be linked to an audio sample now or later.
                </p>
            </div>

            <div class="rounded-xl border bg-card p-6">
                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Name -->
                    <div>
                        <label class="block text-sm font-medium mb-2">
                            Name <span class="text-destructive">*</span>
                        </label>
                        <input
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            placeholder="Enter a name for this transcription"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-destructive">{{ form.errors.name }}</p>
                    </div>

                    <!-- Source Type Tabs -->
                    <div>
                        <label class="block text-sm font-medium mb-2">
                            Source <span class="text-destructive">*</span>
                        </label>
                        <div class="flex gap-2 mb-4">
                            <button
                                type="button"
                                @click="sourceType = 'file'"
                                :class="[
                                    'flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors',
                                    sourceType === 'file'
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted hover:bg-muted/80',
                                ]"
                            >
                                <DocumentArrowUpIcon class="h-4 w-4" />
                                Upload File
                            </button>
                            <button
                                type="button"
                                @click="sourceType = 'text'"
                                :class="[
                                    'flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors',
                                    sourceType === 'text'
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted hover:bg-muted/80',
                                ]"
                            >
                                <DocumentTextIcon class="h-4 w-4" />
                                Paste Text
                            </button>
                            <button
                                type="button"
                                @click="sourceType = 'url'"
                                :class="[
                                    'flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors',
                                    sourceType === 'url'
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted hover:bg-muted/80',
                                ]"
                            >
                                <LinkIcon class="h-4 w-4" />
                                From URL
                            </button>
                        </div>

                        <!-- File Upload -->
                        <div v-if="sourceType === 'file'">
                            <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed rounded-lg cursor-pointer bg-muted/30 hover:bg-muted/50 transition-colors">
                                <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                    <DocumentArrowUpIcon class="h-8 w-8 text-muted-foreground mb-2" />
                                    <p class="text-sm text-muted-foreground">
                                        <span class="font-medium">Click to upload</span> or drag and drop
                                    </p>
                                    <p class="text-xs text-muted-foreground mt-1">
                                        TXT, DOCX, DOC, or PDF (max 10MB)
                                    </p>
                                </div>
                                <input
                                    type="file"
                                    class="hidden"
                                    accept=".txt,.docx,.doc,.pdf"
                                    @change="handleFileChange"
                                />
                            </label>
                            <p v-if="form.file" class="mt-2 text-sm text-muted-foreground">
                                Selected: {{ form.file.name }}
                            </p>
                            <p v-if="form.errors.file" class="mt-1 text-sm text-destructive">{{ form.errors.file }}</p>
                        </div>

                        <!-- Text Input -->
                        <div v-else-if="sourceType === 'text'">
                            <textarea
                                v-model="form.text"
                                rows="8"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                placeholder="Paste your transcript text here..."
                                dir="auto"
                            ></textarea>
                            <p v-if="form.errors.text" class="mt-1 text-sm text-destructive">{{ form.errors.text }}</p>
                        </div>

                        <!-- URL Input -->
                        <div v-else-if="sourceType === 'url'">
                            <input
                                v-model="form.url"
                                type="url"
                                class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                placeholder="https://docs.google.com/document/d/..."
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                Google Docs URL (must be publicly accessible or shared with the service account)
                            </p>
                            <p v-if="form.errors.url" class="mt-1 text-sm text-destructive">{{ form.errors.url }}</p>
                        </div>
                    </div>

                    <!-- Info Box -->
                    <div class="rounded-lg bg-blue-500/10 border border-blue-500/20 p-4">
                        <p class="text-sm text-blue-600 dark:text-blue-400">
                            <strong>Tip:</strong> You can link this transcription to an audio sample later from either the transcription detail page or the audio sample page.
                        </p>
                    </div>

                    <!-- Submit -->
                    <div class="flex items-center gap-4">
                        <button
                            type="submit"
                            :disabled="form.processing || !isFormValid"
                            class="inline-flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-all"
                        >
                            <ArrowPathIcon v-if="form.processing" class="h-4 w-4 animate-spin" />
                            <DocumentTextIcon v-else class="h-4 w-4" />
                            {{ form.processing ? 'Creating...' : 'Create Transcription' }}
                        </button>
                        <a
                            href="/transcriptions"
                            class="text-sm text-muted-foreground hover:text-foreground"
                        >
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
