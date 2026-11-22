<script setup lang="ts">
import { Search } from '@lucide/vue'
import type { SearchGroup, SearchResult } from '~/composables/useGlobalSearch'

/**
 * The search box in the top bar (brand book 07, "Pasek górny"): on every screen,
 * reached with / or Ctrl K, results grouped by what they are, driven from the
 * keyboard - arrows to choose, Enter to open, Esc to close.
 *
 * A combobox in the ARIA sense: focus stays in the input and the chosen option
 * is announced through aria-activedescendant.
 */
const { search } = useGlobalSearch()
const { t } = useI18n()

const input = ref<HTMLInputElement | null>(null)
const query = ref('')
const groups = ref<SearchGroup[]>([])
const open = ref(false)
const failed = ref(false)
const searched = ref('')
const active = ref(0)

const results = computed<SearchResult[]>(() => groups.value.flatMap(g => g.results))
const optionId = (i: number) => `global-search-option-${i}`
const indexOf = (r: SearchResult) => results.value.indexOf(r)

let timer: ReturnType<typeof setTimeout> | undefined
let latest = 0

watch(query, (q) => {
  clearTimeout(timer)
  if (q.trim() === '') {
    groups.value = []
    searched.value = ''
    return
  }
  timer = setTimeout(() => void run(q), 200)
})

async function run(q: string) {
  const ticket = ++latest
  try {
    const found = await search(q)
    // A slower answer to an older query must not replace the newer one.
    if (ticket !== latest) return
    groups.value = found
    failed.value = false
  } catch {
    if (ticket !== latest) return
    groups.value = []
    failed.value = true
  }
  searched.value = q
  active.value = 0
  open.value = true
}

function close() {
  open.value = false
}

async function go(result: SearchResult | undefined) {
  if (!result) return
  close()
  query.value = ''
  input.value?.blur()
  await navigateTo(result.to)
}

function onKey(e: KeyboardEvent) {
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    open.value = true
    const n = results.value.length
    if (n > 0) active.value = (active.value + (e.key === 'ArrowDown' ? 1 : n - 1)) % n
  } else if (e.key === 'Enter') {
    e.preventDefault()
    void go(results.value[active.value])
  } else if (e.key === 'Escape') {
    if (open.value) close()
    else input.value?.blur()
  }
}

// "/" anywhere but in a field, and Ctrl K (Cmd K on a Mac) even from a field.
function onGlobalKey(e: KeyboardEvent) {
  const target = e.target as HTMLElement | null
  const typing = !!target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))

  if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) {
    e.preventDefault()
    input.value?.focus()
  } else if (e.key === '/' && !typing) {
    e.preventDefault()
    input.value?.focus()
  }
}

onMounted(() => window.addEventListener('keydown', onGlobalKey))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onGlobalKey)
  clearTimeout(timer)
})
</script>

<template>
  <div class="relative w-full max-w-md" @focusout="(e: FocusEvent) => { if (!(e.currentTarget as HTMLElement).contains(e.relatedTarget as Node)) close() }">
    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-fg-muted" aria-hidden="true" />
    <input
      ref="input"
      v-model="query"
      type="search"
      role="combobox"
      autocomplete="off"
      :aria-label="t('app.search.label')"
      :aria-expanded="open && searched !== ''"
      aria-controls="global-search-list"
      aria-autocomplete="list"
      :aria-activedescendant="open && results.length ? optionId(active) : undefined"
      :placeholder="t('app.search.placeholder')"
      class="h-9 w-full rounded-control border border-border-input bg-surface pr-16 pl-9 text-sm text-fg placeholder:text-fg-muted focus:border-focus focus:outline-2 focus:outline-offset-0 focus:outline-focus [&::-webkit-search-cancel-button]:hidden"
      data-testid="global-search"
      @focus="open = searched !== ''"
      @keydown="onKey"
    >
    <kbd class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 rounded-sm border border-border px-1.5 font-mono text-xs text-fg-muted" aria-hidden="true">{{ t('app.search.shortcut') }}</kbd>

    <div
      v-if="open && searched !== ''"
      class="absolute top-full left-0 z-50 mt-1 w-full min-w-96 overflow-hidden rounded-control border border-border bg-surface shadow-raised"
    >
      <div id="global-search-list" role="listbox" :aria-label="t('app.search.label')" class="max-h-96 overflow-y-auto py-1">
        <div v-for="(group, g) in groups" :key="group.label" role="group" :aria-labelledby="`global-search-group-${g}`">
          <p :id="`global-search-group-${g}`" class="px-3 pt-2 pb-1 text-xs font-semibold tracking-wide text-fg-muted uppercase">{{ group.label }}</p>
          <div
            v-for="result in group.results"
            :id="optionId(indexOf(result))"
            :key="result.key"
            role="option"
            :aria-selected="indexOf(result) === active"
            :class="['cursor-pointer truncate px-3 py-2 text-sm text-fg', indexOf(result) === active && 'bg-selected']"
            data-testid="global-search-result"
            @mousedown.prevent="go(result)"
            @mousemove="active = indexOf(result)"
          >
            <template v-for="(part, i) in result.parts" :key="i">
              <strong v-if="part.hit" class="font-semibold">{{ part.text }}</strong>
              <template v-else>{{ part.text }}</template>
            </template>
          </div>
        </div>
        <p v-if="failed" class="px-3 py-3 text-sm text-crit" role="alert">{{ t('app.search.failed') }}</p>
        <p v-else-if="results.length === 0" class="px-3 py-3 text-sm text-fg-muted">{{ t('app.search.empty', { query: searched }) }}</p>
      </div>
      <p class="flex gap-4 border-t border-border bg-surface-subtle px-3 py-1.5 text-xs text-fg-muted" aria-hidden="true">
        <span>{{ t('app.search.keys.move') }}</span>
        <span>{{ t('app.search.keys.open') }}</span>
        <span>{{ t('app.search.keys.close') }}</span>
      </p>
    </div>
  </div>
</template>
