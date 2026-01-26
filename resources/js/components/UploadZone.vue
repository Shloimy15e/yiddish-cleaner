<script setup lang="ts">
import { 
    CloudArrowUpIcon, 
    DocumentIcon, 
    XMarkIcon,
    ExclamationTriangleIcon 
} from '@heroicons/vue/24/outline';
import { ref, computed } from 'vue';

const props = withDefaults(defineProps<{
    accept?: string;
    maxSize?: number; // in MB
    disabled?: boolean;
}>(), {
    accept: '.docx,.doc,.txt',
    maxSize: 50,
    disabled: false,
});

const emit = defineEmits<{
    (e: 'file-selected', file: File): void;
    (e: 'file-removed'): void;
}>();

const isDragging = ref(false);
const selectedFile = ref<File | null>(null);
const error = ref<string | null>(null);

const fileInfo = computed(() => {
    if (!selectedFile.value) return null;
    
    const size = selectedFile.value.size;
    const sizeStr = size < 1024 
        ? `${size} B` 
        : size < 1024 * 1024 
            ? `${(size / 1024).toFixed(1)} KB`
            : `${(size / 1024 / 1024).toFixed(1)} MB`;
    
    const ext = selectedFile.value.name.split('.').pop()?.toLowerCase() || '';
    
    return {
        name: selectedFile.value.name,
        size: sizeStr,
        extension: ext,
        isDoc: ext === 'doc',
        isDocx: ext === 'docx',
        isTxt: ext === 'txt',
    };
});

const isDocWarning = computed(() => fileInfo.value?.isDoc);

const acceptedExtensions = computed(() => 
    props.accept.split(',').map(ext => ext.trim().replace('.', '').toLowerCase())
);

const validateFile = (file: File): boolean => {
    error.value = null;
    
    // Check extension
    const ext = file.name.split('.').pop()?.toLowerCase() || '';
    if (!acceptedExtensions.value.includes(ext)) {
        error.value = `Invalid file type. Accepted: ${props.accept}`;
        return false;
    }
    
    // Check size
    if (file.size > props.maxSize * 1024 * 1024) {
        error.value = `File too large. Maximum size: ${props.maxSize}MB`;
        return false;
    }
    
    return true;
};

const handleFile = (file: File) => {
    if (validateFile(file)) {
        selectedFile.value = file;
        emit('file-selected', file);
    }
};

const handleDrop = (event: DragEvent) => {
    isDragging.value = false;
    if (props.disabled) return;
    
    const files = event.dataTransfer?.files;
    if (files?.length) {
        handleFile(files[0]);
    }
};

const handleDragOver = (event: DragEvent) => {
    if (props.disabled) return;
    isDragging.value = true;
};

const handleDragLeave = () => {
    isDragging.value = false;
};

const handleFileInput = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files?.length) {
        handleFile(target.files[0]);
    }
};

const removeFile = () => {
    selectedFile.value = null;
    error.value = null;
    emit('file-removed');
};

// Expose for parent component access
defineExpose({
    selectedFile,
    removeFile,
});
</script>

<template>
    <div class="space-y-3">
        <!-- Drop Zone -->
        <div
            @drop.prevent="handleDrop"
            @dragover.prevent="handleDragOver"
            @dragleave.prevent="handleDragLeave"
            :class="[
                'relative rounded-xl border-2 border-dashed transition-all duration-200 cursor-pointer',
                isDragging 
                    ? 'border-primary bg-primary/5 scale-[1.01]' 
                    : selectedFile 
                        ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/10' 
                        : 'border-border hover:border-primary/50 hover:bg-muted/30',
                disabled && 'opacity-50 cursor-not-allowed',
                error && 'border-red-500 bg-red-50 dark:bg-red-900/10'
            ]"
        >
            <input
                type="file"
                :accept="accept"
                :disabled="disabled"
                @change="handleFileInput"
                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer disabled:cursor-not-allowed"
            />
            
            <!-- Empty State -->
            <div v-if="!selectedFile" class="flex flex-col items-center justify-center py-10 px-6">
                <div :class="[
                    'rounded-full p-4 mb-4 transition-colors',
                    isDragging ? 'bg-primary/10' : 'bg-muted'
                ]">
                    <CloudArrowUpIcon :class="[
                        'w-10 h-10 transition-colors',
                        isDragging ? 'text-primary' : 'text-muted-foreground'
                    ]" />
                </div>
                <p class="text-lg font-medium mb-1">
                    {{ isDragging ? 'Drop your file here' : 'Drag & drop your file' }}
                </p>
                <p class="text-sm text-muted-foreground mb-4">
                    or click to browse
                </p>
                <div class="flex flex-wrap gap-2 justify-center">
                    <span class="inline-flex items-center rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
                        .docx
                    </span>
                    <span class="inline-flex items-center rounded-full bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-600">
                        .doc
                    </span>
                    <span class="inline-flex items-center rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
                        .txt
                    </span>
                </div>
            </div>

            <!-- Selected File Preview -->
            <div v-else class="flex items-center gap-4 p-4">
                <div :class="[
                    'rounded-xl p-3',
                    fileInfo?.isDocx ? 'bg-blue-100 dark:bg-blue-900/30' : 
                    fileInfo?.isDoc ? 'bg-amber-100 dark:bg-amber-900/30' : 
                    'bg-gray-100 dark:bg-gray-800'
                ]">
                    <DocumentIcon :class="[
                        'w-8 h-8',
                        fileInfo?.isDocx ? 'text-blue-600' : 
                        fileInfo?.isDoc ? 'text-amber-600' : 
                        'text-gray-600'
                    ]" />
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-medium truncate">{{ fileInfo?.name }}</p>
                    <p class="text-sm text-muted-foreground">{{ fileInfo?.size }}</p>
                </div>
                <button
                    type="button"
                    @click.stop="removeFile"
                    class="rounded-full p-2 hover:bg-muted transition-colors"
                >
                    <XMarkIcon class="w-5 h-5 text-muted-foreground" />
                </button>
            </div>
        </div>

        <!-- .doc Warning -->
        <div v-if="isDocWarning" class="flex items-start gap-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 p-3">
            <ExclamationTriangleIcon class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
            <div class="text-sm">
                <p class="font-medium text-amber-800 dark:text-amber-400">Legacy .doc format detected</p>
                <p class="text-amber-700 dark:text-amber-500">
                    The file will be converted to .docx format before processing. For best results, consider converting it yourself first.
                </p>
            </div>
        </div>

        <!-- Error Message -->
        <div v-if="error" class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400">
            <ExclamationTriangleIcon class="w-4 h-4" />
            {{ error }}
        </div>
    </div>
</template>
