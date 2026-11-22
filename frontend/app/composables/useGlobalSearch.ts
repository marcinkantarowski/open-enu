import { useApi } from '@ui-kit/composables/useApi'

/**
 * Where a search hit leads, contributed by the module that owns the record.
 *
 * `GET /api/search` answers with the entity's class name and id - it knows what a
 * module declared findable in its `search.php`, not which page shows it. The
 * module's layer says that in `search.ts`, found here by the same glob as
 * `navigation.ts`, so the search box knows no module by name. A hit no module
 * claims is left out: a result that leads nowhere is worse than none.
 */
export interface SearchTarget {
  /** Translation key of the group heading ("Osoby"). */
  group: string
  to: (id: string) => string
  order?: number
}

export function defineSearchTargets(targets: Record<string, SearchTarget>): Record<string, SearchTarget> {
  return targets
}

export interface SearchResult {
  key: string
  to: string
  /** The excerpt cut into plain text and the matched words, for rendering without v-html. */
  parts: Array<{ text: string, hit: boolean }>
}

export interface SearchGroup {
  label: string
  results: SearchResult[]
}

const targetModules = import.meta.glob<{ default: Record<string, SearchTarget> }>(
  '../modules/*/search.ts',
  { eager: true },
)

const targets: Record<string, SearchTarget> = Object.assign({}, ...Object.values(targetModules).map(m => m.default ?? {}))

/**
 * The excerpt is Postgres' `ts_headline`: the record's own text with the matched
 * words in `<b>`. That text is user data, so it is never handed to v-html - it is
 * split on the markers and every part rendered as text.
 */
export function excerptParts(excerpt: string): SearchResult['parts'] {
  return excerpt
    .split(/(<b>.*?<\/b>)/g)
    .filter(Boolean)
    .map(part => part.startsWith('<b>') ? { text: part.slice(3, -4), hit: true } : { text: part, hit: false })
}

interface SearchHit { type: string, id: string, excerpt: string | null }

export function useGlobalSearch() {
  const api = useApi()
  const { t } = useI18n()

  async function search(query: string): Promise<SearchGroup[]> {
    if (query.trim() === '') return []

    const { items } = await api.get<{ items: SearchHit[] }>('/api/search', { query: { q: query } })
    const groups = new Map<string, { order: number, results: SearchResult[] }>()

    for (const hit of items) {
      const target = targets[hit.type]
      if (!target) continue

      const group = groups.get(target.group) ?? { order: target.order ?? 100, results: [] }
      group.results.push({ key: `${hit.type}:${hit.id}`, to: target.to(hit.id), parts: excerptParts(hit.excerpt ?? '') })
      groups.set(target.group, group)
    }

    return [...groups.entries()]
      .sort(([, a], [, b]) => a.order - b.order)
      .map(([label, g]) => ({ label: t(label), results: g.results }))
  }

  return { search }
}
