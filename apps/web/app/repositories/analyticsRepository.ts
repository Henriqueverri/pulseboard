import type { PeriodParams } from '~/types/api'
import type {
  CustomerAnalyticsResponse,
  CustomerSort,
  DashboardResponse,
  ProductAnalyticsResponse,
  ProductSort,
  RankingParams,
  RevenueParams,
  RevenueResponse,
  TransactionStatusResponse,
} from '~/types/analytics'

/** `from`/`to` must be sent together (civil dates in the organization timezone). */
export function useAnalyticsRepository() {
  const { apiFetch } = useApiClient()

  return {
    dashboard: (params: PeriodParams) =>
      apiFetch<DashboardResponse>('/dashboard', { query: { ...params } }),

    revenue: (params: RevenueParams) =>
      apiFetch<RevenueResponse>('/analytics/revenue', { query: { ...params } }),

    products: (params: RankingParams<ProductSort>) =>
      apiFetch<ProductAnalyticsResponse>('/analytics/products', { query: { ...params } }),

    customers: (params: RankingParams<CustomerSort>) =>
      apiFetch<CustomerAnalyticsResponse>('/analytics/customers', { query: { ...params } }),

    transactionStatus: (params: PeriodParams) =>
      apiFetch<TransactionStatusResponse>('/analytics/transactions', { query: { ...params } }),
  }
}
