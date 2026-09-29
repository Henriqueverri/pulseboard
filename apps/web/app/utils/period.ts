import type { CivilDate } from '~/types/api'
import { addDays, daysBetween, endOfPreviousMonth, isCivilDate, startOfMonth } from './date'

/** Mirrors `AnalyticsRequest::MAX_DAYS`. */
export const MAX_PERIOD_DAYS = 366

export type Granularity = 'day' | 'week' | 'month'

export const PERIOD_PRESETS = ['7d', '30d', '90d', '12m', 'mtd', 'last-month'] as const

export type PeriodPreset = typeof PERIOD_PRESETS[number]

export const DEFAULT_PRESET: PeriodPreset = '30d'

export const PRESET_LABELS: Record<PeriodPreset | 'custom', string> = {
  '7d': 'Últimos 7 dias',
  '30d': 'Últimos 30 dias',
  '90d': 'Últimos 90 dias',
  '12m': 'Últimos 12 meses',
  'mtd': 'Este mês',
  'last-month': 'Mês passado',
  'custom': 'Personalizado',
}

export interface CivilRange {
  from: CivilDate
  to: CivilDate
}

export function isPeriodPreset(value: unknown): value is PeriodPreset {
  return typeof value === 'string' && (PERIOD_PRESETS as readonly string[]).includes(value)
}

/** Preset ranges end today (inclusive), like the API default of "last 30 days including today". */
export function resolvePreset(preset: PeriodPreset, today: CivilDate): CivilRange {
  switch (preset) {
    case '7d':
      return { from: addDays(today, -6), to: today }
    case '30d':
      return { from: addDays(today, -29), to: today }
    case '90d':
      return { from: addDays(today, -89), to: today }
    case '12m':
      return { from: addDays(today, -364), to: today }
    case 'mtd':
      return { from: startOfMonth(today), to: today }
    case 'last-month': {
      const to = endOfPreviousMonth(today)

      return { from: startOfMonth(to), to }
    }
  }
}

/** Client-side mirror of the API validation, so invalid ranges are never requested. */
export function validateRange(from: string, to: string): string | null {
  if (!isCivilDate(from) || !isCivilDate(to)) {
    return 'Informe datas válidas.'
  }

  if (to < from) {
    return 'A data final deve ser igual ou posterior à inicial.'
  }

  if (daysBetween(from, to) > MAX_PERIOD_DAYS) {
    return `O período pode ter no máximo ${MAX_PERIOD_DAYS} dias.`
  }

  return null
}

/** Default bucket size for a period length, keeping charts between ~7 and ~60 points. */
export function suggestGranularity(days: number): Granularity {
  if (days <= 45) {
    return 'day'
  }

  if (days <= 180) {
    return 'week'
  }

  return 'month'
}

export function isGranularity(value: unknown): value is Granularity {
  return value === 'day' || value === 'week' || value === 'month'
}
