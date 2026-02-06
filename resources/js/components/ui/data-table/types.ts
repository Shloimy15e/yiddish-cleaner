import type { HTMLAttributes } from 'vue'

export interface ColumnDef<T = Record<string, unknown>> {
  /** Unique key identifying this column. Used for slot names (#cell-{key}) and default value lookup. */
  key: string

  /** Display label for the column header. */
  label: string

  /** Whether clicking this header triggers a sort event. */
  sortable?: boolean

  /** Text/content alignment. Default: 'left' */
  align?: 'left' | 'center' | 'right'

  /**
   * Responsive hiding classes applied to both <th> and <td>.
   * Example: 'hidden md:table-cell'
   */
  hideBelow?: string

  /** Additional classes for the header <th>. */
  headerClass?: HTMLAttributes['class']

  /** Additional classes for each body <td> in this column. */
  cellClass?: HTMLAttributes['class']
}

export interface LaravelPagination<T = unknown> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}
