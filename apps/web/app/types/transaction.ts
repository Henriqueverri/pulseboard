import type { IsoDateTime, Money } from './api'

export type TransactionStatus = 'paid' | 'pending' | 'refunded' | 'canceled'

/** API order of statuses (also used by `/analytics/transactions`). */
export const TRANSACTION_STATUSES: readonly TransactionStatus[] = ['paid', 'refunded', 'pending', 'canceled']

/** `TransactionResource` without relations (e.g. a customer's recent transactions). */
export interface TransactionSummary {
  id: string
  status: TransactionStatus
  total_amount: Money
  occurred_at: IsoDateTime
}

export interface TransactionCustomer {
  id: string
  name: string
  email: string
  is_deleted: boolean
}

export interface TransactionItemProduct {
  id: string
  name: string
  sku: string | null
  is_deleted: boolean
}

export interface TransactionItem {
  id: string
  quantity: number
  unit_price: Money
  line_total: Money
  product: TransactionItemProduct
}

/** `GET /transactions` row. */
export interface Transaction extends TransactionSummary {
  items_count: number
  customer: TransactionCustomer
}

/** `GET /transactions/{id}`. */
export interface TransactionDetail extends Transaction {
  items: TransactionItem[]
}

export interface TransactionListParams {
  q?: string
  status?: TransactionStatus
  customer_id?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
}
