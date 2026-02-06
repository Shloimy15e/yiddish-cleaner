<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>

<template>
  <th data-slot="table-head" :class="cn('px-4 py-3 text-left text-sm font-medium', props.class)">
    <slot />
  </th>
</template>
