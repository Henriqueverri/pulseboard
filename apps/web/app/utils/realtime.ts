import type { ConnectionStatus } from 'laravel-echo'
import type {
  RealtimeStatus,
  StockChangedEvent,
  TransactionRecordedEvent,
  TransactionStatusChangedEvent,
} from '~/types/realtime'
import { formatMoney } from './format'

/** Read-only screens fed by sales and stock; insights (paid AI calls) and API keys stay as they are. */
const LIVE_RESOURCES = new Set(['dashboard', 'transactions', 'analytics', 'products', 'customers'])

/**
 * `true` for the `useAsyncData` keys of those screens in the given organization, e.g.
 * `dashboard:kpis:{orgId}`, `transactions:list:{orgId}`, `products:detail:{orgId}:{id}`.
 */
export function isLiveDataKey(key: string, organizationId: string): boolean {
  const [resource, ...rest] = key.split(':')

  return LIVE_RESOURCES.has(resource ?? '') && rest.includes(organizationId)
}

export function toRealtimeStatus(status: ConnectionStatus): RealtimeStatus {
  switch (status) {
    case 'connected':
      return 'live'
    case 'connecting':
      return 'connecting'
    default:
      return 'offline'
  }
}

export interface LiveNotice {
  title: string
  tone: 'success' | 'info'
}

export function transactionRecordedNotice(event: TransactionRecordedEvent): LiveNotice | null {
  return event.status === 'paid'
    ? { title: `Nova venda ${formatMoney(event.total_amount, event.currency)}`, tone: 'success' }
    : null
}

export function transactionStatusChangedNotice(event: TransactionStatusChangedEvent): LiveNotice | null {
  return event.to === 'paid'
    ? { title: `Venda confirmada ${formatMoney(event.total_amount, event.currency)}`, tone: 'success' }
    : null
}

/** Only when a tracked product enters low or zero stock, not on every movement. */
export function stockChangedNotice(event: StockChangedEvent): LiveNotice | null {
  if (!event.tracked || event.status === event.previous_status) {
    return null
  }

  if (event.status === 'out') {
    return { title: `Estoque zerado: ${event.name}`, tone: 'info' }
  }

  if (event.status === 'low' && event.previous_status !== 'out') {
    return { title: `Estoque baixo: ${event.name}`, tone: 'info' }
  }

  return null
}
