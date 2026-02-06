<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = withDefaults(defineProps<{
  currentPage: number
  lastPage: number
  perPage: number
  total: number
  noun?: string
  class?: HTMLAttributes['class']
}>(), {
  noun: 'items',
})

const emit = defineEmits<{
  'page-change': [page: number]
}>()

const { visiblePages } = useSmartPagination(
  () => props.currentPage,
  () => props.lastPage,
)

const showingFrom = computed(() => (props.currentPage - 1) * props.perPage + 1)
const showingTo = computed(() => Math.min(props.currentPage * props.perPage, props.total))
</script>

<template>
  <div
    v-if="lastPage > 1"
    data-slot="table-pagination"
    :class="cn('flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between', props.class)"
  >
    <span class="text-sm text-muted-foreground">
      Showing {{ showingFrom }} to {{ showingTo }} of {{ total }} {{ noun }}
    </span>
    <div class="flex flex-wrap gap-1">
      <button
        @click="emit('page-change', currentPage - 1)"
        :disabled="currentPage === 1"
        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-50 hover:bg-muted"
      >
        Previous
      </button>
      <template v-for="(page, idx) in visiblePages" :key="idx">
        <span v-if="page === 'ellipsis'" class="px-2 py-1 text-muted-foreground">...</span>
        <button
          v-else
          @click="emit('page-change', page)"
          :class="[
            'rounded-lg px-3 py-1 text-sm',
            page === currentPage
              ? 'bg-primary text-primary-foreground'
              : 'border hover:bg-muted',
          ]"
        >
          {{ page }}
        </button>
      </template>
      <button
        @click="emit('page-change', currentPage + 1)"
        :disabled="currentPage === lastPage"
        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-50 hover:bg-muted"
      >
        Next
      </button>
    </div>
  </div>
</template>
