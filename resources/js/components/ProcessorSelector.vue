<script setup lang="ts">
import { AdjustmentsHorizontalIcon, CheckIcon } from '@heroicons/vue/24/outline';
import { ref, computed, watch } from 'vue';

interface Processor {
    name: string;
    description: string;
}

const props = defineProps<{
    processors: Record<string, Processor>;
    modelValue: string[];
    presetProcessors?: string[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string[]): void;
}>();

// Processor groupings for UI organization
const processorGroups = {
    'Text Cleanup': ['whitespace', 'special_chars'],
    'Titles & Headings': ['title_style', 'seif_marker'],
    'Non-Speech Notes': ['brackets_inline', 'parentheses', 'editorial_hebrew', 'force_remove'],
};

// Selected processors
const selected = ref<Set<string>>(new Set(props.modelValue));

// Watch for external changes
watch(() => props.modelValue, (newVal) => {
    selected.value = new Set(newVal);
});

// Watch for preset changes
watch(() => props.presetProcessors, (newVal) => {
    if (newVal) {
        selected.value = new Set(newVal);
        emit('update:modelValue', Array.from(selected.value));
    }
});

// Toggle processor selection
const toggle = (name: string) => {
    if (selected.value.has(name)) {
        selected.value.delete(name);
    } else {
        selected.value.add(name);
    }
    emit('update:modelValue', Array.from(selected.value));
};

// Select all
const selectAll = () => {
    selected.value = new Set(Object.keys(props.processors));
    emit('update:modelValue', Array.from(selected.value));
};

// Clear all
const clearAll = () => {
    selected.value = new Set();
    emit('update:modelValue', []);
};

// Computed properties
const selectedCount = computed(() => selected.value.size);
const totalCount = computed(() => Object.keys(props.processors).length);

// Get processors for a group
const getGroupProcessors = (group: string) => {
    const groupNames = processorGroups[group as keyof typeof processorGroups] || [];
    return groupNames.filter(name => name in props.processors);
};

// Get ungrouped processors
const ungroupedProcessors = computed(() => {
    const grouped = new Set(Object.values(processorGroups).flat());
    return Object.keys(props.processors).filter(name => !grouped.has(name));
});
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <AdjustmentsHorizontalIcon class="w-5 h-5 text-primary" />
                <span class="font-medium">Processing Plugins</span>
                <span class="text-xs bg-muted px-2 py-0.5 rounded-full text-muted-foreground">
                    {{ selectedCount }} / {{ totalCount }} selected
                </span>
            </div>
            <div class="flex gap-2 text-sm">
                <button 
                    type="button" 
                    @click="selectAll"
                    class="text-primary hover:text-primary/80 font-medium"
                >
                    Select All
                </button>
                <span class="text-muted-foreground">|</span>
                <button 
                    type="button" 
                    @click="clearAll"
                    class="text-muted-foreground hover:text-foreground font-medium"
                >
                    Clear All
                </button>
            </div>
        </div>

        <!-- Processor Groups -->
        <div class="space-y-4">
            <template v-for="(groupProcessors, groupName) in processorGroups" :key="groupName">
                <div v-if="getGroupProcessors(groupName).length > 0">
                    <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">
                        {{ groupName }}
                    </h4>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="name in getGroupProcessors(groupName)"
                            :key="name"
                            class="group cursor-pointer"
                        >
                            <input 
                                type="checkbox" 
                                :checked="selected.has(name)"
                                @change="toggle(name)"
                                class="sr-only peer"
                            />
                            <span 
                                :class="[
                                    'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border-2 text-sm font-medium transition-all',
                                    selected.has(name) 
                                        ? 'bg-primary/10 border-primary text-primary' 
                                        : 'border-border bg-background text-muted-foreground hover:border-primary/50 hover:bg-muted/50'
                                ]"
                            >
                                <span 
                                    :class="[
                                        'w-4 h-4 rounded border-2 flex items-center justify-center transition-all',
                                        selected.has(name) 
                                            ? 'bg-primary border-primary' 
                                            : 'border-muted-foreground/50'
                                    ]"
                                >
                                    <CheckIcon 
                                        v-if="selected.has(name)" 
                                        class="w-3 h-3 text-primary-foreground" 
                                    />
                                </span>
                                {{ processors[name]?.name || name }}
                            </span>
                        </label>
                    </div>
                </div>
            </template>

            <!-- Ungrouped processors -->
            <div v-if="ungroupedProcessors.length > 0">
                <h4 class="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-2">
                    Other
                </h4>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="name in ungroupedProcessors"
                        :key="name"
                        class="group cursor-pointer"
                    >
                        <input 
                            type="checkbox" 
                            :checked="selected.has(name)"
                            @change="toggle(name)"
                            class="sr-only peer"
                        />
                        <span 
                            :class="[
                                'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border-2 text-sm font-medium transition-all',
                                selected.has(name) 
                                    ? 'bg-primary/10 border-primary text-primary' 
                                    : 'border-border bg-background text-muted-foreground hover:border-primary/50 hover:bg-muted/50'
                            ]"
                        >
                            <span 
                                :class="[
                                    'w-4 h-4 rounded border-2 flex items-center justify-center transition-all',
                                    selected.has(name) 
                                        ? 'bg-primary border-primary' 
                                        : 'border-muted-foreground/50'
                                ]"
                            >
                                <CheckIcon 
                                    v-if="selected.has(name)" 
                                    class="w-3 h-3 text-primary-foreground" 
                                />
                            </span>
                            {{ processors[name]?.name || name }}
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Processor descriptions tooltip area -->
        <p v-if="selectedCount > 0" class="text-xs text-muted-foreground">
            Selected processors will run in order: {{ Array.from(selected).join(' → ') }}
        </p>
    </div>
</template>
