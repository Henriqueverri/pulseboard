import type { StockStatus } from './analytics'
import type { TransactionStatus } from './transaction'

export interface EnabledRealtimeConfig {
  enabled: true
  key: string
  host: string
  port: number
  scheme: 'http' | 'https'
  auth_endpoint: string
}

/** GET /realtime: where to open the WebSocket (Laravel Reverb, Pusher protocol). */
export type RealtimeConfig = { enabled: false } | EnabledRealtimeConfig

/** Shown by the header indicator: `off` when the API has no realtime server. */
export type RealtimeStatus = 'off' | 'connecting' | 'live' | 'offline'

/** Events on `private-organization.{id}`; amounts are decimal strings, as in the REST API. */
export interface TransactionRecordedEvent {
  id: string
  status: TransactionStatus
  total_amount: string
  currency: string
  occurred_at: string
}

export interface TransactionStatusChangedEvent {
  id: string
  from: TransactionStatus
  to: TransactionStatus
  total_amount: string
  currency: string
  occurred_at: string
}

export interface StockChangedEvent {
  product_id: string
  sku: string
  name: string
  tracked: boolean
  quantity: number | null
  min_quantity: number | null
  status: StockStatus | null
  previous_status: StockStatus | null
}
