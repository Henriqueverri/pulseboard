import { useAnalyticsRepository } from '~/repositories/analyticsRepository'
import type { ReportingPeriod } from './useReportingPeriod'

export const DASHBOARD_RANKING_LIMIT = 5

/**
 * The five dashboard sections load in parallel and fail independently: one broken
 * endpoint shows an error in its own card while the others keep working.
 */
export function useDashboard(period: ReportingPeriod) {
  const repository = useAnalyticsRepository()
  const auth = useAuthStore()

  const key = (section: string) => () => `dashboard:${section}:${auth.organization?.id ?? 'none'}`

  const kpis = useAsyncData(key('kpis'), () => repository.dashboard(period.params.value), {
    watch: [period.params],
  })

  const revenue = useAsyncData(
    key('revenue'),
    () => repository.revenue({ ...period.params.value, granularity: period.granularity.value }),
    { watch: [period.params, period.granularity] },
  )

  const status = useAsyncData(key('status'), () => repository.transactionStatus(period.params.value), {
    watch: [period.params],
  })

  const products = useAsyncData(
    key('products'),
    () => repository.products({ ...period.params.value, limit: DASHBOARD_RANKING_LIMIT }),
    { watch: [period.params] },
  )

  const customers = useAsyncData(
    key('customers'),
    () => repository.customers({ ...period.params.value, limit: DASHBOARD_RANKING_LIMIT }),
    { watch: [period.params] },
  )

  return { kpis, revenue, status, products, customers }
}
