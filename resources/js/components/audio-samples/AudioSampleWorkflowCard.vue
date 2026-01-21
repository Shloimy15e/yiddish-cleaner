<script setup lang="ts">
interface Requirement {
    label: string;
    missing: boolean;
}

const props = defineProps<{
    title: string;
    description: string;
    actionLabel: string;
    actionHref: string;
    actionDisabled: boolean;
    actionReason?: string | null;
    requirements: Requirement[];
}>();
</script>

<template>
    <div class="rounded-2xl border bg-card p-4 sm:p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="text-xs font-semibold uppercase text-muted-foreground">
                    Current step
                </div>
                <h2 class="text-lg font-semibold">{{ title }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <div class="flex flex-col items-start gap-1 sm:items-end">
                <a
                    :href="actionHref"
                    :class="[
                        'inline-flex h-10 items-center justify-center rounded-lg px-4 text-sm font-medium',
                        actionDisabled
                            ? 'cursor-not-allowed border border-border bg-muted text-muted-foreground'
                            : 'bg-primary text-primary-foreground hover:bg-primary/90',
                    ]"
                    :aria-disabled="actionDisabled"
                >
                    {{ actionLabel }}
                </a>
                <span
                    v-if="actionDisabled && actionReason"
                    class="text-xs text-muted-foreground"
                >
                    {{ actionReason }}
                </span>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <span
                v-for="req in requirements"
                :key="req.label"
                :class="[
                    'inline-flex items-center rounded-full border px-2.5 py-0.5',
                    req.missing
                        ? 'border-amber-200 bg-amber-50 text-amber-700'
                        : 'border-border bg-muted/40 text-muted-foreground',
                ]"
            >
                {{ req.missing ? `Missing ${req.label}` : req.label }}
            </span>
        </div>
    </div>
</template>
