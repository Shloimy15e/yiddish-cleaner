import { computed, ref, type Ref, type MaybeRefOrGetter, toValue } from 'vue'

export function useTableSelection<T extends Record<string, any>>(
  items: MaybeRefOrGetter<T[]>,
  keyField: string = 'id',
) {
  const selectedIds = ref<Set<number | string>>(new Set()) as Ref<Set<number | string>>

  const selectedCount = computed(() => selectedIds.value.size)

  const allSelected = computed(() => {
    const list = toValue(items)
    return list.length > 0 && list.every((item) => selectedIds.value.has(item[keyField]))
  })

  const someSelected = computed(() => {
    return selectedIds.value.size > 0 && !allSelected.value
  })

  const isSelected = (item: T): boolean => {
    return selectedIds.value.has(item[keyField])
  }

  const toggleSelection = (item: T): void => {
    const key = item[keyField]
    const newSet = new Set(selectedIds.value)
    if (newSet.has(key)) {
      newSet.delete(key)
    } else {
      newSet.add(key)
    }
    selectedIds.value = newSet
  }

  const toggleSelectAll = (): void => {
    const list = toValue(items)
    if (allSelected.value) {
      selectedIds.value = new Set()
    } else {
      selectedIds.value = new Set(list.map((item) => item[keyField]))
    }
  }

  const clearSelection = (): void => {
    selectedIds.value = new Set()
  }

  const selectedArray = computed(() => Array.from(selectedIds.value))

  return {
    selectedIds,
    selectedCount,
    allSelected,
    someSelected,
    isSelected,
    toggleSelection,
    toggleSelectAll,
    clearSelection,
    selectedArray,
  }
}
