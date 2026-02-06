<script setup lang="ts" generic="T extends Record<string, any>">
import { ArrowUpIcon, ArrowDownIcon } from '@heroicons/vue/24/outline'
import type { HTMLAttributes } from 'vue'
import type { ColumnDef } from './types'
import { cn } from '@/lib/utils'

const props = withDefaults(defineProps<{
  columns: ColumnDef<T>[]
  items: T[]
  itemKey?: string
  selectable?: boolean
  rowLink?: (item: T) => string
  sortKey?: string
  sortDirection?: 'asc' | 'desc'
  emptyMessage?: string
  class?: HTMLAttributes['class']
  tableClass?: HTMLAttributes['class']
}>(), {
  itemKey: 'id',
  selectable: false,
  emptyMessage: 'No results found.',
})

const emit = defineEmits<{
  sort: [key: string]
  'row-click': [item: T]
}>()

const selectedIds = defineModel<Set<number | string>>('selected', {
  default: () => new Set(),
})

const totalColumns = computed(() => {
  return props.columns.length + (props.selectable ? 1 : 0)
})

const allSelected = computed(() => {
  return props.items.length > 0 &&
    props.items.every((item) => selectedIds.value.has(item[props.itemKey]))
})

const someSelected = computed(() => {
  return !allSelected.value &&
    props.items.some((item) => selectedIds.value.has(item[props.itemKey]))
})

const toggleSelectAll = () => {
  if (allSelected.value) {
    const newSet = new Set(selectedIds.value)
    props.items.forEach((item) => newSet.delete(item[props.itemKey]))
    selectedIds.value = newSet
  } else {
    const newSet = new Set(selectedIds.value)
    props.items.forEach((item) => newSet.add(item[props.itemKey]))
    selectedIds.value = newSet
  }
}

const toggleRow = (item: T) => {
  const key = item[props.itemKey]
  const newSet = new Set(selectedIds.value)
  if (newSet.has(key)) {
    newSet.delete(key)
  } else {
    newSet.add(key)
  }
  selectedIds.value = newSet
}

const isSelected = (item: T) => selectedIds.value.has(item[props.itemKey])

const alignClass = (align?: string) => {
  if (align === 'center') return 'text-center'
  if (align === 'right') return 'text-right'
  return 'text-left'
}

const getValue = (item: T, key: string): unknown => {
  return key.split('.').reduce((obj: any, k) => obj?.[k], item)
}
</script>

<template>
  <Table :class="props.class" :table-class="props.tableClass">
    <TableHeader>
      <tr>
        <TableHead v-if="selectable" class="w-12">
          <Checkbox
            :checked="allSelected ? true : someSelected ? 'indeterminate' : false"
            @update:checked="toggleSelectAll"
          />
        </TableHead>

        <TableHead
          v-for="col in columns"
          :key="col.key"
          :class="cn(
            alignClass(col.align),
            col.hideBelow,
            col.sortable && 'cursor-pointer select-none hover:text-primary',
            col.headerClass,
          )"
          @click="col.sortable ? emit('sort', col.key) : undefined"
        >
          <span v-if="col.sortable" class="inline-flex items-center gap-1">
            <slot :name="`header-${col.key}`" :column="col">
              {{ col.label }}
            </slot>
            <ArrowUpIcon v-if="sortKey === col.key && sortDirection === 'asc'" class="h-4 w-4" />
            <ArrowDownIcon v-if="sortKey === col.key && sortDirection === 'desc'" class="h-4 w-4" />
          </span>
          <slot v-else :name="`header-${col.key}`" :column="col">
            {{ col.label }}
          </slot>
        </TableHead>
      </tr>
    </TableHeader>

    <TableBody>
      <TableRow
        v-for="item in items"
        :key="item[itemKey]"
        :selected="selectable && isSelected(item)"
        :href="rowLink?.(item)"
        @click="emit('row-click', item)"
      >
        <TableCell v-if="selectable">
          <Checkbox
            :checked="isSelected(item)"
            @update:checked="toggleRow(item)"
          />
        </TableCell>

        <TableCell
          v-for="col in columns"
          :key="col.key"
          :class="cn(alignClass(col.align), col.hideBelow, col.cellClass)"
        >
          <slot :name="`cell-${col.key}`" :item="item" :value="getValue(item, col.key)">
            {{ getValue(item, col.key) ?? '—' }}
          </slot>
        </TableCell>
      </TableRow>

      <TableEmpty v-if="items.length === 0" :colspan="totalColumns">
        <slot name="empty">
          {{ emptyMessage }}
        </slot>
      </TableEmpty>
    </TableBody>
  </Table>
</template>
