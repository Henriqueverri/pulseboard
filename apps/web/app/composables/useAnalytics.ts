import type { LocationQuery } from 'vue-router'
import { useAnalyticsRepository } from '~/repositories/analyticsRepository'
import { CUSTOMER_SORTS, PRODUCT_SORTS } from '~/types/analytics'
import type { CustomerSort, ProductSort } from '~/types/analytics'
import type { ReportingPeriod } from './useReportingPeriod'

export const RANKING_LIMITS = [10, 25, 50] as const
export const DEFAULT_RANKING_LIMIT = 10
/** Mirrors `ProductAnalyticsRequest::MAX_LIMIT` / `CustomerAnalyticsRequest::MAX_LIMIT`. */
const MAX_RANKING_LIMIT = 50

function first(value: LocationQuery[string] | undefined): string | undefined {
  const candidate = Array.isArray(value) ? value[0] : value

  return typeof candidate === 'string' ? candidate : undefined
}

/** Ranking `sort`/`limit` in the URL; defaults are omitted and invalid values ignored. */
export function useRankingQuery<S extends string>(sorts: readonly S[], defaultSort: S) {
  const route = useRoute()
  const router = useRouter()

  const sort = computed<S>(() => {
    const value = first(route.query.sort)

    return (sorts as readonly string[]).includes(value ?? '') ? value as S : defaultSort
  })

  const limit = computed(() => {
    const raw = first(route.query.limit)
    const value = raw && /^\d+$/.test(raw) ? Number(raw) : Number.NaN

    return value >= 1 && value <= MAX_RANKING_LIMIT ? value : DEFAULT_RANKING_LIMIT
  })

  function replace(key: string, value: string | undefined) {
    const query = Object.fromEntries(Object.entries(route.query).filter(([name]) => name !== key))

    return router.replace({ query: value === undefined ? query : { ...query, [key]: value } })
  }

  return {
    sort,
    limit,
    setSort: (value: S) => replace('sort', value === defaultSort ? undefined : value),
    setLimit: (value: number) => replace('limit', value === DEFAULT_RANKING_LIMIT ? undefined : String(value)),
  }
}

function useOrganizationKey(name: string) {
  const auth = useAuthStore()

  return () => `analytics:${name}:${auth.organization?.id ?? 'none'}`
}

export function useRevenueAnalytics(period: ReportingPeriod) {
  const repository = useAnalyticsRepository()

  return useAsyncData(
    useOrganizationKey('revenue'),
    () => repository.revenue({ ...period.params.value, granularity: period.granularity.value }),
    { watch: [period.params, period.granularity] },
  )
}

export function useProductAnalytics(period: ReportingPeriod) {
  const repository = useAnalyticsRepository()
  const ranking = useRankingQuery<ProductSort>(PRODUCT_SORTS, 'revenue')
  const params = computed(() => ({ ...period.params.value, sort: ranking.sort.value, limit: ranking.limit.value }))

  return {
    ranking,
    ...useAsyncData(useOrganizationKey('products'), () => repository.products(params.value), { watch: [params] }),
  }
}

export function useCustomerAnalytics(period: ReportingPeriod) {
  const repository = useAnalyticsRepository()
  const ranking = useRankingQuery<CustomerSort>(CUSTOMER_SORTS, 'revenue')
  const params = computed(() => ({ ...period.params.value, sort: ranking.sort.value, limit: ranking.limit.value }))

  return {
    ranking,
    ...useAsyncData(useOrganizationKey('customers'), () => repository.customers(params.value), { watch: [params] }),
  }
}

export function useTransactionStatusAnalytics(period: ReportingPeriod) {
  const repository = useAnalyticsRepository()

  return useAsyncData(
    useOrganizationKey('status'),
    () => repository.transactionStatus(period.params.value),
    { watch: [period.params] },
  )
}
