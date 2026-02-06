<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
  selected?: boolean
  href?: string
  clickable?: boolean
}>()

const rowClass = computed(() => cn(
  'hover:bg-muted/30 transition-colors',
  (props.href || props.clickable) && 'cursor-pointer',
  props.selected && 'bg-primary/5',
  props.class,
))
</script>

<template>
  <Link
    v-if="href"
    :href="href"
    as="tr"
    data-slot="table-row"
    :class="rowClass"
  >
    <slot />
  </Link>
  <tr
    v-else
    data-slot="table-row"
    :class="rowClass"
  >
    <slot />
  </tr>
</template>
