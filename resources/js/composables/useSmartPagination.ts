import { computed, type MaybeRefOrGetter, toValue } from 'vue'

export function useSmartPagination(
  currentPage: MaybeRefOrGetter<number>,
  lastPage: MaybeRefOrGetter<number>,
  delta: number = 2,
) {
  const visiblePages = computed(() => {
    const current = toValue(currentPage)
    const last = toValue(lastPage)
    const pages: (number | 'ellipsis')[] = []

    pages.push(1)

    const rangeStart = Math.max(2, current - delta)
    const rangeEnd = Math.min(last - 1, current + delta)

    if (rangeStart > 2) {
      pages.push('ellipsis')
    }

    for (let i = rangeStart; i <= rangeEnd; i++) {
      pages.push(i)
    }

    if (rangeEnd < last - 1) {
      pages.push('ellipsis')
    }

    if (last > 1) {
      pages.push(last)
    }

    return pages
  })

  return { visiblePages }
}
