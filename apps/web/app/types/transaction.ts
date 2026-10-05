import type { IsoDateTime, Money } from './api'

export type TransactionStatus = 'paid' | 'pending' | 'refunded' | 'canceled'

/** API order of statuses (also used by `/analytics/transactions`). */
export const TRANSACTION_STATUSES: readonly TransactionStatus[] = ['paid', 'refunded', 'pending', 'canceled']

export const TRANSACTION_STATUS_LABELS: Record<TransactionStatus, string> = {
  paid: 'Pago',
  refunded: 'Reembolsado',
  pending: 'Pendente',
  canceled: 'Cancelado',
}

/** Where a transaction (or one of its status changes) came from: the demo seed or the ingestion API. */
export type TransactionSource = 'seed' | 'ingest'

export const TRANSACTION_SOURCE_LABELS: Record<TransactionSource, string> = {
  seed: 'Demo',
  ingest: 'Integração',
}

export const TRANSACTION_SOURCE_DESCRIPTIONS: Record<TransactionSource, string> = {
  seed: 'Gerada pelos dados de demonstração.',
  ingest: 'Recebida pela API de ingestão, autenticada por API Key.',
}

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

/**
 * `TransactionStatusChangeResource`: one step of the lifecycle. `from_status` is null on creation;
 * `occurred_at` is the business time reported by the source, `recorded_at` when PulseBoard stored it.
 */
export interface TransactionStatusChange {
  from_status: TransactionStatus | null
  to_status: TransactionStatus
  occurred_at: IsoDateTime
  recorded_at: IsoDateTime
  source: TransactionSource
}

interface TransactionRecord extends TransactionSummary {
  /** The integrating system's id; null for seeded transactions. */
  external_id: string | null
  source: TransactionSource
  customer: TransactionCustomer
}

/** `GET /transactions` row. */
export interface Transaction extends TransactionRecord {
  items_count: number
}

/** `GET /transactions/{id}`: `status_history` is in lifecycle order, as returned by the API. */
export interface TransactionDetail extends TransactionRecord {
  items: TransactionItem[]
  status_history: TransactionStatusChange[]
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
