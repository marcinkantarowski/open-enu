<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import { PaginationEllipsis, PaginationList, PaginationListItem, PaginationNext, PaginationPrev, PaginationRoot } from 'reka-ui'

/**
 * Pages of a server-paged list. `page` is 1-based, as the URL and the person
 * reading it count; `total` is the number of rows, not of pages.
 */
const page = defineModel<number>('page', { default: 1 })

defineProps<{ total: number, perPage: number }>()

const { t } = useI18n()
const item = 'inline-flex h-control-sm min-w-control-sm items-center justify-center rounded-control px-2 text-sm text-fg transition-colors hover:bg-surface-subtle disabled:pointer-events-none disabled:opacity-40'
</script>

<template>
  <PaginationRoot
    v-slot="{ page: current }"
    v-model:page="page"
    :total="total"
    :items-per-page="perPage"
    :sibling-count="1"
    show-edges
  >
    <nav :aria-label="t('table.pagination')">
      <PaginationList v-slot="{ items }" class="flex items-center gap-1">
        <PaginationPrev :class="item" :aria-label="t('table.previous')">
          <ChevronLeft class="size-4" aria-hidden="true" />
        </PaginationPrev>
        <template v-for="(entry, index) in items" :key="index">
          <PaginationListItem
            v-if="entry.type === 'page'"
            :value="entry.value"
            :class="cn(item, 'tabular-nums', entry.value === current && 'bg-selected font-semibold text-accent-700')"
          >
            {{ entry.value }}
          </PaginationListItem>
          <PaginationEllipsis v-else :index="index" class="px-1 text-fg-muted">…</PaginationEllipsis>
        </template>
        <PaginationNext :class="item" :aria-label="t('table.next')">
          <ChevronRight class="size-4" aria-hidden="true" />
        </PaginationNext>
      </PaginationList>
    </nav>
  </PaginationRoot>
</template>
