import { addDays, daysBetween, startOfMonth, todayIn, type CivilDate } from './dates';

export const PERIOD_PRESETS = ['today', '7d', '30d', 'month'] as const;

export type PeriodPreset = (typeof PERIOD_PRESETS)[number];

export const DEFAULT_PRESET: PeriodPreset = '30d';

export const PRESET_LABELS: Record<PeriodPreset, string> = {
  today: 'Hoje',
  '7d': '7 dias',
  '30d': '30 dias',
  month: 'Este mês',
};

/** How the period reads inside a sentence ("R$ 1.200,00 nos últimos 7 dias"). */
export const PRESET_PHRASES: Record<PeriodPreset, string> = {
  today: 'hoje',
  '7d': 'nos últimos 7 dias',
  '30d': 'nos últimos 30 dias',
  month: 'neste mês',
};

export interface CivilRange {
  from: CivilDate;
  to: CivilDate;
}

export type Granularity = 'day' | 'week';

/** Presets end today (inclusive) in the organization's calendar, like the API's default "last 30 days". */
export function presetRange(preset: PeriodPreset, timezone: string, now: Date = new Date()): CivilRange {
  const today = todayIn(timezone, now);

  switch (preset) {
    case 'today':
      return { from: today, to: today };
    case '7d':
      return { from: addDays(today, -6), to: today };
    case '30d':
      return { from: addDays(today, -29), to: today };
    case 'month':
      return { from: startOfMonth(today), to: today };
  }
}

/** Daily buckets up to a month; weekly beyond that. */
export function granularityFor({ from, to }: CivilRange): Granularity {
  return daysBetween(from, to) <= 31 ? 'day' : 'week';
}
