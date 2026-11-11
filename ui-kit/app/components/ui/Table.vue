<script setup lang="ts" generic="Row extends object">
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue'
import { rowSortingFeature, tableFeatures, useTable, type ColumnDef, type SortingState } from '@tanstack/vue-table'

/**
 * A table with the parts that are always needed and always rebuilt: column
 * definitions, sorting, a loading state that does not collapse the layout, and an
 * empty state that is not a blank rectangle. TanStack Table underneath (ADR-0024),
 * so selection, visibility and pinning are features to switch on, not rewrites.
 *
 * Sorting is the SERVER's: bind `v-model:sort`, and the page asks the API for the
 * rows in that order. The table never sorts the page it was handed - a sorted
 * first page of an unsorted list is a wrong answer that looks right.
 *
 * Generic over the row type so a page passes its own interface and the `#cell-*`
 * slots receive it typed.
 */
export interface Column {
  key: string
  label: string
  /** Right-aligned for anything numeric, so digits line up to be compared. */
  numeric?: boolean
  sortable?: boolean
}

export interface Sort {
  key: string
  desc: boolean
}

const props = defineProps<{
  columns: Column[]
  rows: readonly Row[]
  rowKey?: string
  loading?: boolean
  emptyTitle?: string
  /** The caption a screen reader announces for the table; visually hidden. */
  caption?: string
}>()

const sort = defineModel<Sort | null>('sort', { default: null })

defineSlots<{
  [key: `cell-${string}`]: (props: { row: Row, value: unknown }) => unknown
}>()

const { t } = useI18n()

/** Columns are addressed by name, which the row type cannot express. */
const cell = (row: Row, key: string): unknown => (row as Record<string, unknown>)[key]

const features = tableFeatures({ rowSortingFeature })

const columnDefs = computed<Array<ColumnDef<typeof features, Row>>>(() => props.columns.map(column => ({
  id: column.key,
  header: column.label,
  accessorFn: (row: Row) => cell(row, column.key),
  enableSorting: Boolean(column.sortable),
})))

const sorting = computed<SortingState>(() => (sort.value ? [{ id: sort.value.key, desc: sort.value.desc }] : []))

const table = useTable({
  features,
  columns: columnDefs,
  data: computed(() => props.rows),
  manualSorting: true,
  enableSortingRemoval: false,
  get state() { return { sorting: sorting.value } },
  onSortingChange: (updater) => {
    const next = typeof updater === 'function' ? updater(sorting.value) : updater
    const first = next[0]
    sort.value = first ? { key: first.id, desc: first.desc } : null
  },
  getRowId: (row: Row, index: number) => String(cell(row, props.rowKey ?? 'id') ?? index),
})

const byKey = computed(() => new Map(props.columns.map(column => [column.key, column])))
const ariaSort = (key: string): 'ascending' | 'descending' | undefined => {
  if (sort.value?.key !== key) return undefined
  return sort.value.desc ? 'descending' : 'ascending'
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full border-collapse text-sm">
      <caption v-if="props.caption" class="sr-only">{{ props.caption }}</caption>
      <thead>
        <tr class="border-b border-border bg-surface-subtle text-left">
          <th
            v-for="header in table.getHeaderGroups()[0]?.headers ?? []"
            :key="header.id"
            scope="col"
            :aria-sort="ariaSort(header.id)"
            :class="cn('h-10 px-4 text-xs font-semibold text-fg-muted', byKey.get(header.id)?.numeric && 'text-right')"
          >
            <button
              v-if="header.column.getCanSort()"
              type="button"
              :class="cn('-mx-1 inline-flex items-center gap-1 rounded-sm px-1 hover:text-fg', byKey.get(header.id)?.numeric && 'flex-row-reverse')"
              :aria-label="t('table.sortBy', { column: byKey.get(header.id)?.label })"
              @click="header.column.toggleSorting(sort?.key === header.id ? !sort.desc : false)"
            >
              {{ byKey.get(header.id)?.label }}
              <ArrowUp v-if="ariaSort(header.id) === 'ascending'" class="size-3.5 text-fg" aria-hidden="true" />
              <ArrowDown v-else-if="ariaSort(header.id) === 'descending'" class="size-3.5 text-fg" aria-hidden="true" />
              <ArrowUpDown v-else class="size-3.5 opacity-60" aria-hidden="true" />
            </button>
            <template v-else>{{ byKey.get(header.id)?.label }}</template>
          </th>
        </tr>
      </thead>

      <tbody>
        <tr v-if="props.loading">
          <td :colspan="props.columns.length" class="px-4 py-8 text-center text-fg-muted">
            <UiSpinner class="mx-auto size-5" />
            <span class="sr-only">{{ t('common.loading') }}</span>
          </td>
        </tr>

        <tr v-else-if="props.rows.length === 0">
          <td :colspan="props.columns.length">
            <UiEmptyState :title="props.emptyTitle" />
          </td>
        </tr>

        <!-- v-for lives inside the v-else template: Vue gives v-if higher
             precedence than v-for on the same element, so the two together read
             as working by accident rather than by intent. -->
        <template v-else>
          <tr
            v-for="row in table.getRowModel().rows"
            :key="row.id"
            class="border-b border-border last:border-0 hover:bg-surface-subtle"
          >
            <td
              v-for="column in props.columns"
              :key="column.key"
              :class="cn('h-10 px-4 py-2 align-middle text-fg', column.numeric && 'text-right tabular-nums')"
            >
              <slot :name="`cell-${column.key}`" :row="row.original" :value="cell(row.original, column.key)">
                {{ cell(row.original, column.key) ?? '-' }}
              </slot>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
