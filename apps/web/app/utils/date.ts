import type { CivilDate, IsoDateTime } from '~/types/api'

const LOCALE = 'pt-BR'
const CIVIL_DATE = /^(\d{4})-(\d{2})-(\d{2})$/
const DAY_MS = 86_400_000

/**
 * Civil dates (`YYYY-MM-DD`) are calendar days in the organization's timezone.
 * They are handled as UTC midnights so formatting never shifts the day.
 */
function civilToUtc(date: CivilDate): Date {
  const match = CIVIL_DATE.exec(date)

  if (!match) {
    throw new RangeError(`Invalid civil date: ${date}`)
  }

  return new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])))
}

function utcToCivil(date: Date): CivilDate {
  return date.toISOString().slice(0, 10)
}

export function isCivilDate(value: unknown): value is CivilDate {
  if (typeof value !== 'string' || !CIVIL_DATE.test(value)) {
    return false
  }

  return utcToCivil(civilToUtc(value)) === value
}

export function addDays(date: CivilDate, days: number): CivilDate {
  return utcToCivil(new Date(civilToUtc(date).getTime() + days * DAY_MS))
}

/** Number of days in the inclusive range (same day = 1). */
export function daysBetween(from: CivilDate, to: CivilDate): number {
  return Math.round((civilToUtc(to).getTime() - civilToUtc(from).getTime()) / DAY_MS) + 1
}

export function startOfMonth(date: CivilDate): CivilDate {
  return `${date.slice(0, 7)}-01`
}

export function endOfPreviousMonth(date: CivilDate): CivilDate {
  return addDays(startOfMonth(date), -1)
}

/** Today's calendar date in `timezone` (never the browser's timezone). */
export function todayIn(timezone: string, now: Date = new Date()): CivilDate {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now)

  const get = (type: string) => parts.find(part => part.type === type)?.value ?? ''

  return `${get('year')}-${get('month')}-${get('day')}`
}

const civilFormatters = {
  short: new Intl.DateTimeFormat(LOCALE, { timeZone: 'UTC', day: '2-digit', month: '2-digit', year: 'numeric' }),
  medium: new Intl.DateTimeFormat(LOCALE, { timeZone: 'UTC', day: '2-digit', month: 'short', year: 'numeric' }),
  dayMonth: new Intl.DateTimeFormat(LOCALE, { timeZone: 'UTC', day: '2-digit', month: 'short' }),
  monthYear: new Intl.DateTimeFormat(LOCALE, { timeZone: 'UTC', month: 'short', year: 'numeric' }),
}

function clean(text: string): string {
  return text.replace(/\./g, '').replace(/\s+de\s+/g, ' ')
}

/** 2026-09-01 → "01/09/2026" (short), "01 set 2026" (medium), "01 set" (dayMonth), "set 2026" (monthYear). */
export function formatCivilDate(date: CivilDate, style: keyof typeof civilFormatters = 'short'): string {
  return clean(civilFormatters[style].format(civilToUtc(date)))
}

/** Inclusive range label: "01 – 30 set 2026", "28 ago – 03 set 2026", "15 dez 2025 – 10 jan 2026". */
export function formatCivilRange(from: CivilDate, to: CivilDate): string {
  if (from === to) {
    return formatCivilDate(from, 'medium')
  }

  const [fromYear, fromMonth, fromDay] = from.split('-')
  const [toYear, toMonth] = to.split('-')

  if (fromYear !== toYear) {
    return `${formatCivilDate(from, 'medium')} – ${formatCivilDate(to, 'medium')}`
  }

  if (fromMonth !== toMonth) {
    return `${formatCivilDate(from, 'dayMonth')} – ${formatCivilDate(to, 'medium')}`
  }

  return `${fromDay} – ${formatCivilDate(to, 'medium')}`
}

const dateTimeFormatters = new Map<string, Intl.DateTimeFormat>()

function dateTimeFormatter(timezone: string, withTime: boolean): Intl.DateTimeFormat {
  const key = `${timezone}:${withTime}`
  let formatter = dateTimeFormatters.get(key)

  if (!formatter) {
    formatter = new Intl.DateTimeFormat(LOCALE, {
      timeZone: timezone,
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    })
    dateTimeFormatters.set(key, formatter)
  }

  return formatter
}

/** UTC instant from the API shown in the organization's timezone ("15/09/2026 11:32"). */
export function formatDateTime(value: IsoDateTime | null | undefined, timezone: string, options: { time?: boolean } = {}): string {
  if (!value) {
    return '—'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return '—'
  }

  return dateTimeFormatter(timezone, options.time ?? true).format(date).replace(',', '')
}
