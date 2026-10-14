import type { TagTone } from '~/components/ui/Tag.vue'
import type { InventoryItem, StockStatus } from '~/types/analytics'
import { formatInteger } from './format'

export const STOCK_STATUS: Record<StockStatus, { label: string, tone: TagTone }> = {
  ok: { label: 'Em dia', tone: 'success' },
  low: { label: 'Baixo', tone: 'warning' },
  out: { label: 'Zerado', tone: 'danger' },
}

const coverFormatter = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 })

export function formatUnits(quantity: number): string {
  return `${formatInteger(quantity)} un.`
}

/** How long the balance lasts at the average daily sales of the window. */
export function formatDaysOfCover(days: number | null, windowDays: number): string {
  if (days === null) {
    return `Sem vendas nos últimos ${windowDays} dias`
  }

  if (days === 0) {
    return 'Sem saldo'
  }

  if (days < 1) {
    return 'Acaba hoje'
  }

  return `Dura cerca de ${coverFormatter.format(days)} ${days < 2 ? 'dia' : 'dias'}`
}

/** Products that need attention: out of stock or at or below the minimum, as ordered by the API. */
export function itemsNeedingAttention(items: InventoryItem[]): InventoryItem[] {
  return items.filter(item => item.status !== 'ok')
}
