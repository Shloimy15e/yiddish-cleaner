<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  colspan: number
  class?: HTMLAttributes['class']
}>()
</script>

<template>
  <tr data-slot="table-empty">
    <td :colspan="colspan" :class="cn('px-4 py-8 text-center text-muted-foreground', props.class)">
      <slot />
    </td>
  </tr>
</template>
