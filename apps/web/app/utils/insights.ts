import type { LocationQueryRaw, RouteLocationRaw } from 'vue-router'
import type { InsightCaveat, InsightDestination, InsightEvidence, InsightKind } from '~/types/insights'
import { INSIGHT_DESTINATIONS } from '~/types/insights'
import type { CivilRange, PeriodPreset } from './period'
import { PRESET_LABELS } from './period'
import { formatCivilRange } from './date'
import { formatInteger, formatMoney } from './format'

const ANALYTICS_PATHS: Partial<Record<InsightDestination, string>> = {
  'dashboard': '/dashboard',
  'analytics.revenue': '/analytics/revenue',
  'analytics.products': '/analytics/products',
  'analytics.customers': '/analytics/customers',
  'analytics.transactions': '/analytics/transactions',
}

const TRANSACTION_STATUS: Partial<Record<InsightDestination, string>> = {
  'transactions.refunded': 'refunded',
  'transactions.canceled': 'canceled',
  'transactions.pending': 'pending',
}

export const DESTINATION_LABELS: Record<InsightDestination, string> = {
  'dashboard': 'Dashboard',
  'analytics.revenue': 'Analytics de receita',
  'analytics.products': 'Analytics de produtos',
  'analytics.customers': 'Analytics de clientes',
  'analytics.transactions': 'Analytics de transações',
  'transactions.refunded': 'Transações reembolsadas',
  'transactions.canceled': 'Transações canceladas',
  'transactions.pending': 'Transações pendentes',
}

export function isInsightDestination(value: unknown): value is InsightDestination {
  return typeof value === 'string' && (INSIGHT_DESTINATIONS as readonly string[]).includes(value)
}

/**
 * Route of a destination with the same period: Dashboard and Analytics keep the period keys
 * of the URL (`period.query`); the transactions list filters by status and the resolved
 * `from`/`to`. `null` for values outside the enum, so the model can never produce a link.
 */
export function destinationRoute(
  destination: string,
  period: { query: LocationQueryRaw, range: CivilRange },
): RouteLocationRaw | null {
  if (!isInsightDestination(destination)) {
    return null
  }

  const path = ANALYTICS_PATHS[destination]
  if (path) {
    return { path, query: { ...period.query } }
  }

  return {
    path: '/transactions',
    query: { status: TRANSACTION_STATUS[destination], from: period.range.from, to: period.range.to },
  }
}

/** Period in a sentence ("últimos 30 dias", "01 – 30 ago 2026"), for accessible link names. */
export function periodLabel(preset: PeriodPreset | 'custom', range: CivilRange): string {
  return preset === 'custom' ? formatCivilRange(range.from, range.to) : PRESET_LABELS[preset].toLowerCase()
}

export const KIND_LABELS: Record<InsightKind, string> = {
  positive: 'Ponto positivo',
  negative: 'Ponto negativo',
  neutral: 'Observação',
  attention: 'Atenção',
}

export const CAVEAT_LABELS: Record<InsightCaveat, string> = {
  partial_period: 'O período termina hoje ou depois: os números ainda podem mudar até o fim do dia.',
  no_sales: 'Não houve vendas pagas no período.',
  no_previous_data: 'O período anterior não teve vendas pagas, então não há variação para comparar.',
  low_volume: 'Poucos pedidos no período: variações podem não indicar tendência.',
  status_is_current: 'Os status são os atuais: um reembolso posterior altera o período da venda original.',
}

/** Unknown caveats (a newer API) are skipped instead of shown raw. */
export function caveatLabels(caveats: string[]): string[] {
  return caveats.flatMap(caveat => CAVEAT_LABELS[caveat as InsightCaveat] ?? [])
}

export function formatEvidenceValue(evidence: Pick<InsightEvidence, 'format' | 'value'>, currency: string): string {
  return evidence.format === 'money' ? formatMoney(evidence.value, currency) : formatInteger(evidence.value)
}

/** Time the quota frees up (`Retry-After` seconds from now), in the organization's timezone. */
export function quotaResetTime(retryAfter: number, timezone: string, now: Date = new Date()): string {
  return new Intl.DateTimeFormat('pt-BR', { timeZone: timezone, hour: '2-digit', minute: '2-digit' })
    .format(new Date(now.getTime() + retryAfter * 1000))
}
