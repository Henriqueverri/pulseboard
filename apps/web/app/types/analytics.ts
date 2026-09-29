import type { AnalyticsMeta, CivilDate, Comparison, Money, PeriodParams } from './api'
import type { Granularity } from '~/utils/period'
import type { ProductStatus } from './product'
import type { TransactionStatus } from './transaction'

export interface AnalyticsResponse<T, S = never, M = object> {
  data: T
  meta: AnalyticsMeta & M
  summary: S
}

/** `GET /dashboard`: paid transactions only; `average_order_value` is `null` without orders. */
export interface DashboardKpis {
  revenue: Comparison<Money>
  orders: Comparison<number>
  average_order_value: Comparison<Money | null>
  customers: Comparison<number>
}

export interface DashboardResponse {
  data: DashboardKpis
  meta: AnalyticsMeta
}

/** One bucket of the revenue series; `from`/`to` are clipped to the requested period. */
export interface RevenueBucket {
  bucket: CivilDate
  from: CivilDate
  to: CivilDate
  revenue: Money
  orders: number
}

export interface RevenueSummary {
  revenue: Comparison<Money>
  orders: Comparison<number>
}

export type RevenueResponse = AnalyticsResponse<RevenueBucket[], RevenueSummary, { granularity: Granularity }>

export interface RevenueParams extends PeriodParams {
  granularity?: Granularity
}

export interface RankedProduct {
  id: string
  name: string
  sku: string | null
  status: ProductStatus
  is_deleted: boolean
}

export interface ProductRankingRow {
  rank: number
  product: RankedProduct
  revenue: Comparison<Money>
  units_sold: Comparison<number>
}

export interface ProductAnalyticsSummary {
  revenue: Comparison<Money>
  units_sold: Comparison<number>
  products_sold: Comparison<number>
}

export const PRODUCT_SORTS = ['revenue', 'units_sold'] as const
export type ProductSort = typeof PRODUCT_SORTS[number]

export type ProductAnalyticsResponse = AnalyticsResponse<
  ProductRankingRow[],
  ProductAnalyticsSummary,
  { sort: ProductSort, limit: number }
>

export interface RankedCustomer {
  id: string
  name: string
  email: string
  is_deleted: boolean
}

export interface CustomerRankingRow {
  rank: number
  customer: RankedCustomer
  revenue: Comparison<Money>
  orders: Comparison<number>
}

export interface CustomerAnalyticsSummary {
  total_customers: Comparison<number>
  active_customers: Comparison<number>
  new_customers: Comparison<number>
  returning_customers: Comparison<number>
}

export const CUSTOMER_SORTS = ['revenue', 'orders'] as const
export type CustomerSort = typeof CUSTOMER_SORTS[number]

export type CustomerAnalyticsResponse = AnalyticsResponse<
  CustomerRankingRow[],
  CustomerAnalyticsSummary,
  { sort: CustomerSort, limit: number }
>

export interface RankingParams<S extends string> extends PeriodParams {
  sort?: S
  limit?: number
}

/** Every status, including non-paid ones; `percentage` is `null` when the period has no transactions. */
export interface TransactionStatusRow {
  status: TransactionStatus
  orders: Comparison<number>
  revenue: Comparison<Money>
  percentage: Comparison<number | null>
}

/** Presentation row of `RankingList`, built from product or customer rankings. */
export interface RankingItem {
  key: string
  rank: number
  label: string
  sublabel?: string | null
  /** Detail link; omitted for removed entities, which have no detail page. */
  to?: string | null
  removed?: boolean
  value: string
  /** Bar length relative to the first item (0–1). */
  share: number
  change?: Comparison<number | string | null>
}

export interface TransactionStatusResponse {
  data: TransactionStatusRow[]
  meta: AnalyticsMeta
}
