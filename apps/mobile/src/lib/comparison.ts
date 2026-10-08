import type { Comparison, DashboardMetrics } from '@/api/types';

import { formatInteger, formatMoney } from './money';

export type Trend = 'up' | 'down' | 'flat' | 'none';
export type Tone = 'positive' | 'negative' | 'neutral';

export interface ChangeDescription {
  trend: Trend;
  tone: Tone;
  /** Badge text: "+12,5%", "−3,0%", "0,0%" or "—". */
  text: string;
  /** Sentence for screen readers. */
  label: string;
}

const percentFormatter = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

/** Below this absolute change (in %) the period counts as stable. */
const STABLE_THRESHOLD = 1;

function formatPercent(value: number): string {
  return `${percentFormatter.format(value)}%`;
}

/** Signed percent variation: 22.5 → "+22,5%", -3 → "−3,0%". */
export function formatChange(change: number): string {
  if (change === 0) {
    return formatPercent(0);
  }

  return `${change > 0 ? '+' : '−'}${formatPercent(Math.abs(change))}`;
}

/** A `{ value, previous, change }` metric of the API, for metrics where growth is good. */
export function describeChange(metric: Comparison<unknown>): ChangeDescription {
  const { change } = metric;

  if (change === null) {
    return { trend: 'none', tone: 'neutral', text: '—', label: 'Sem base de comparação com o período anterior.' };
  }

  if (change === 0) {
    return { trend: 'flat', tone: 'neutral', text: formatChange(0), label: 'Estável em relação ao período anterior.' };
  }

  const up = change > 0;

  return {
    trend: up ? 'up' : 'down',
    tone: up ? 'positive' : 'negative',
    text: formatChange(change),
    label: `${up ? 'Aumento' : 'Queda'} de ${formatPercent(Math.abs(change))} em relação ao período anterior.`,
  };
}

function revenueTrend(change: number | null): string {
  if (change === null) {
    return 'sem base de comparação com o período anterior';
  }

  if (Math.abs(change) < STABLE_THRESHOLD) {
    return 'estável em relação ao período anterior';
  }

  return `${formatPercent(Math.abs(change))} ${change > 0 ? 'acima' : 'abaixo'} do período anterior`;
}

/**
 * The dashboard in one sentence, built from the API comparisons (no AI):
 * "R$ 12.500,00 nos últimos 30 dias, 22,5% acima do período anterior, com 98 vendas."
 */
export function quickSummary(metrics: DashboardMetrics, currency: string, periodPhrase: string): string {
  const orders = metrics.orders.value;

  if (orders === 0) {
    return `Nenhuma venda ${periodPhrase}.`;
  }

  const sales = orders === 1 ? '1 venda' : `${formatInteger(orders)} vendas`;

  return `${formatMoney(metrics.revenue.value, currency)} ${periodPhrase}, ${revenueTrend(metrics.revenue.change)}, com ${sales}.`;
}
