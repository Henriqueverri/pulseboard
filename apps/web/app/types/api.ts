/**
 * Decimal amount serialized by the API as a string with two decimals ("1250.00").
 * Never do arithmetic on it; format it for display only.
 */
export type Money = string

/** Calendar date in the organization's business calendar (`YYYY-MM-DD`). */
export type CivilDate = string

/** UTC instant serialized by Laravel (ISO 8601). */
export type IsoDateTime = string

/**
 * A metric in the current period next to the same metric in the previous period
 * (`App\Support\Analytics\Comparison`). `change` is the percent variation with one
 * decimal: `0` when both sides are zero, `null` when previous is zero or a side is null.
 */
export interface Comparison<V = number> {
  value: V
  previous: V
  change: number | null
}

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  per_page: number
  to: number | null
  total: number
  path: string
}

export interface PaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface Paginated<T> {
  data: T[]
  links: PaginationLinks
  meta: PaginationMeta
}

export interface ResourceEnvelope<T> {
  data: T
}

export interface ListParams {
  page?: number
  per_page?: number
  q?: string
}

export interface PeriodRange {
  from: CivilDate
  to: CivilDate
  days: number
}

export interface AnalyticsMeta {
  period: PeriodRange
  previous_period: PeriodRange
  timezone: string
  currency: string
}

export interface PeriodParams {
  from?: CivilDate
  to?: CivilDate
}

export interface ApiErrorBody {
  message?: string
  errors?: Record<string, string[]>
}
