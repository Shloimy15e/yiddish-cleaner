<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
  tableClass?: HTMLAttributes['class']
}>()
</script>

<template>
  <div data-slot="table-container" :class="cn('rounded-xl border bg-card overflow-hidden', props.class)">
    <div class="overflow-x-auto">
      <table data-slot="table" :class="cn('w-full', props.tableClass)">
        <slot />
      </table>
    </div>
  </div>
</template>
