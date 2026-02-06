<script setup lang="ts" generic="T extends string | number">
import {
    Listbox,
    ListboxButton,
    ListboxOption,
    ListboxOptions,
} from '@headlessui/vue';
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';

import { cn } from '@/lib/utils';

export interface SelectOption {
    value: T;
    label: string;
    description?: string;
}

const props = withDefaults(
    defineProps<{
        options: SelectOption[];
        disabled?: boolean;
        class?: HTMLAttributes['class'];
        buttonClass?: HTMLAttributes['class'];
        placeholder?: string;
    }>(),
    {
        placeholder: 'Select...',
    },
);

const model = defineModel<T>({ required: true });

const selectedLabel = computed(() => {
    const option = props.options.find((o) => o.value === model.value);
    return option?.label ?? props.placeholder;
});
</script>

<template>
    <Listbox v-model="model" :disabled="disabled">
        <div :class="cn('relative', props.class)">
            <ListboxButton
                :class="cn(
                    'relative w-full rounded-lg border bg-background py-2 pl-3 pr-10 text-left text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:opacity-50',
                    buttonClass,
                )"
            >
                <slot name="trigger" :selected-label="selectedLabel" :selected-value="model">
                    <span class="block truncate">{{ selectedLabel }}</span>
                </slot>
                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                    <ChevronUpDownIcon class="h-5 w-5 text-muted-foreground" />
                </span>
            </ListboxButton>
            <ListboxOptions
                class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border bg-background py-1 shadow-lg focus:outline-none"
            >
                <ListboxOption
                    v-for="option in options"
                    :key="String(option.value)"
                    :value="option.value"
                    v-slot="{ active, selected }"
                >
                    <li
                        :class="[
                            'relative cursor-pointer select-none py-2 pl-10 pr-4 text-sm',
                            active ? 'bg-primary/10 text-foreground' : 'text-foreground',
                        ]"
                    >
                        <span :class="['block truncate', selected && 'font-medium']">
                            {{ option.label }}
                        </span>
                        <span v-if="option.description" class="block truncate text-xs text-muted-foreground">
                            {{ option.description }}
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
</template>
