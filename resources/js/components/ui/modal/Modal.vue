<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    DialogTitle,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';
import type { HTMLAttributes } from 'vue';
import { computed, useSlots } from 'vue';

import { cn } from '@/lib/utils';

type MaxWidth = 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl' | '5xl';

const props = withDefaults(
    defineProps<{
        show: boolean;
        maxWidth?: MaxWidth;
        panelClass?: HTMLAttributes['class'];
        closeable?: boolean;
    }>(),
    {
        maxWidth: '2xl',
        closeable: true,
    },
);

const emit = defineEmits<{
    close: [];
    afterEnter: [];
}>();

const slots = useSlots();

const maxWidthClass = computed(() => {
    const widths: Record<MaxWidth, string> = {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        '3xl': 'sm:max-w-3xl',
        '4xl': 'sm:max-w-4xl',
        '5xl': 'sm:max-w-5xl',
    };
    return widths[props.maxWidth];
});

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};
</script>

<template>
    <TransitionRoot appear :show="show" as="template" @after-enter="emit('afterEnter')">
        <Dialog as="div" @close="close" class="relative z-50">
            <TransitionChild
                as="template"
                enter="duration-300 ease-out"
                enter-from="opacity-0"
                enter-to="opacity-100"
                leave="duration-200 ease-in"
                leave-from="opacity-100"
                leave-to="opacity-0"
            >
                <div class="fixed inset-0 bg-black/25 dark:bg-black/50" />
            </TransitionChild>

            <div class="fixed inset-0 overflow-y-auto">
                <div class="flex min-h-full items-center justify-center p-4">
                    <TransitionChild
                        as="template"
                        enter="duration-300 ease-out"
                        enter-from="opacity-0 scale-95"
                        enter-to="opacity-100 scale-100"
                        leave="duration-200 ease-in"
                        leave-from="opacity-100 scale-100"
                        leave-to="opacity-0 scale-95"
                    >
                        <DialogPanel
                            :class="cn(
                                'w-full transform rounded-2xl bg-background border shadow-xl transition-all',
                                maxWidthClass,
                                panelClass,
                            )"
                        >
                            <!-- Header with title + close button -->
                            <div v-if="slots.title" class="flex items-center justify-between border-b px-6 py-4">
                                <DialogTitle class="text-lg font-semibold">
                                    <slot name="title" />
                                </DialogTitle>
                                <button v-if="closeable" @click="close" class="rounded-lg p-1 text-muted-foreground hover:bg-muted">
                                    <XMarkIcon class="h-5 w-5" />
                                </button>
                            </div>

                            <slot />

                            <!-- Footer -->
                            <div v-if="slots.footer" class="border-t px-6 py-4">
                                <slot name="footer" />
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
