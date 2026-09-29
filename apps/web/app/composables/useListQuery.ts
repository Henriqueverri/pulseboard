import type { LocationQuery } from 'vue-router'
import { isCivilDate } from '~/utils/date'

export const PER_PAGE_OPTIONS = [15, 25, 50] as const
export const DEFAULT_PER_PAGE = 15
const MAX_PER_PAGE = 100

type FilterParser<T> = (raw: string | undefined) => T | undefined
type Parsers<F> = { [K in keyof F]: FilterParser<F[K]> }

function first(value: LocationQuery[string] | undefined): string | undefined {
  const candidate = Array.isArray(value) ? value[0] : value

  return typeof candidate === 'string' ? candidate : undefined
}

function positiveInt(raw: string | undefined): number | undefined {
  if (!raw || !/^\d+$/.test(raw)) {
    return undefined
  }

  const value = Number(raw)

  return value >= 1 ? value : undefined
}

export const textFilter: FilterParser<string> = (raw) => {
  const value = raw?.trim().slice(0, 255)

  return value ? value : undefined
}

export function enumFilter<T extends string>(values: readonly T[]): FilterParser<T> {
  return raw => (values as readonly string[]).includes(raw ?? '') ? raw as T : undefined
}

export const uuidFilter: FilterParser<string> = raw =>
  raw && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(raw) ? raw : undefined

export const civilDateFilter: FilterParser<string> = raw => raw && isCivilDate(raw) ? raw : undefined

/**
 * List state (filters, page, per_page) lives in the URL: reload, back/forward and shared links
 * keep the same view. Invalid values in the URL are ignored instead of reaching the API.
 */
export function useListQuery<F extends Record<string, string | undefined>>(parsers: Parsers<F>) {
  const route = useRoute()
  const router = useRouter()
  const keys = Object.keys(parsers) as Array<keyof F & string>

  const page = computed(() => positiveInt(first(route.query.page)) ?? 1)
  const perPage = computed(() => {
    const value = positiveInt(first(route.query.per_page))

    return value && value <= MAX_PER_PAGE ? value : DEFAULT_PER_PAGE
  })

  const filters = computed(() => Object.fromEntries(
    keys.map(key => [key, parsers[key](first(route.query[key]))]),
  ) as F)

  const hasActiveFilters = computed(() => keys.some(key => filters.value[key] !== undefined))

  const params = computed(() => {
    const active = Object.fromEntries(
      Object.entries(filters.value).filter(([, value]) => value !== undefined),
    ) as Partial<F>

    return { ...active, page: page.value, per_page: perPage.value }
  })

  function update(patch: Record<string, string | number | undefined>) {
    const removed = new Set<string>()
    const set: Record<string, string> = {}

    for (const [key, value] of Object.entries(patch)) {
      const isDefault = (key === 'page' && value === 1) || (key === 'per_page' && value === DEFAULT_PER_PAGE)

      if (value === undefined || value === '' || isDefault) {
        removed.add(key)
      }
      else {
        set[key] = String(value)
      }
    }

    const query: LocationQuery = Object.fromEntries(
      Object.entries(route.query).filter(([key]) => !removed.has(key)),
    )

    return router.replace({ query: { ...query, ...set } })
  }

  return {
    page,
    perPage,
    filters,
    params,
    hasActiveFilters,
    /** Changing a filter always goes back to the first page. */
    setFilter: <K extends keyof F & string>(key: K, value: F[K] | undefined) =>
      update({ [key]: value, page: undefined }),
    setPage: (value: number) => update({ page: value }),
    setPerPage: (value: number) => update({ per_page: value, page: undefined }),
    clearFilters: () => update({
      ...Object.fromEntries(keys.map(key => [key, undefined])),
      page: undefined,
    }),
  }
}

export type ListQuery<F extends Record<string, string | undefined>> = ReturnType<typeof useListQuery<F>>
