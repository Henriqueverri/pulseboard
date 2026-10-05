import type { IsoDateTime, Money } from './api'
import type { TransactionSummary } from './transaction'

/** `CustomerResource`. */
export interface Customer {
  id: string
  name: string
  email: string
  /** The customer's id in an integrating system; the ingestion API resolves customers by it. */
  external_id: string | null
  created_at: IsoDateTime
  updated_at: IsoDateTime
}

/** `GET /customers/{id}`: totals over paid transactions; the 5 latest transactions of any status. */
export interface CustomerDetail extends Customer {
  orders_count: number
  total_spent: Money
  recent_transactions: TransactionSummary[]
}

export interface CustomerInput {
  name: string
  email: string
  external_id: string | null
}

export interface CustomerListParams {
  q?: string
  page?: number
  per_page?: number
}
