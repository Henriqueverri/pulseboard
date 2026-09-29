import type { Comparison } from '~/types/api'
import { formatChange } from './format'

export type Trend = 'up' | 'down' | 'flat' | 'new' | 'none'
export type Tone = 'positive' | 'negative' | 'neutral'

/**
 * `positive`: growth is good (revenue). `negative`: growth is bad (refunds, cancellations).
 * `neutral`: direction carries no judgment (shares of pending transactions).
 */
export type Polarity = 'positive' | 'negative' | 'neutral'

export interface ChangeDescription {
  trend: Trend
  tone: Tone
  /** Short badge text: "+12,5%", "−3,0%", "0,0%", "Novo" or "—". */
  text: string
  /** Screen-reader friendly sentence. */
  label: string
}

function isZero(value: number | string | null): boolean {
  return value !== null && Number(value) === 0
}

/**
 * Describes a `{ value, previous, change }` metric, covering every case of the API contract:
 * growth, decline, stability (`0.0`), no base in the previous period (`null` with previous 0)
 * and undefined values (`null`, e.g. average order value without orders).
 */
export function describeChange(
  metric: Comparison<number | string | null>,
  polarity: Polarity = 'positive',
): ChangeDescription {
  const { value, previous, change } = metric

  if (change === null) {
    if (value !== null && previous !== null && isZero(previous) && !isZero(value)) {
      return {
        trend: 'new',
        tone: 'neutral',
        text: 'Novo',
        label: 'Sem base de comparação: o período anterior foi zero.',
      }
    }

    return { trend: 'none', tone: 'neutral', text: '—', label: 'Sem comparação disponível.' }
  }

  if (change === 0) {
    return { trend: 'flat', tone: 'neutral', text: formatChange(0), label: 'Estável em relação ao período anterior.' }
  }

  const trend: Trend = change > 0 ? 'up' : 'down'
  const good = polarity === 'positive' ? change > 0 : change < 0
  const tone: Tone = polarity === 'neutral' ? 'neutral' : good ? 'positive' : 'negative'
  const text = formatChange(change)

  return {
    trend,
    tone,
    text,
    label: `${change > 0 ? 'Aumento' : 'Queda'} de ${text.replace(/^[+−]/, '')} em relação ao período anterior.`,
  }
}
