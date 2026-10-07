/** Calendar day (`YYYY-MM-DD`) in the organization's timezone, as the API expects in `from`/`to`. */
export type CivilDate = string;

const CIVIL_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
const DAY_MS = 86_400_000;

/** Civil dates are handled as UTC midnights, so arithmetic and formatting never shift the day. */
function civilToUtc(date: CivilDate): Date {
  const match = CIVIL_DATE.exec(date);

  if (!match) {
    throw new RangeError(`Invalid civil date: ${date}`);
  }

  return new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
}

function utcToCivil(date: Date): CivilDate {
  return date.toISOString().slice(0, 10);
}

export function addDays(date: CivilDate, days: number): CivilDate {
  return utcToCivil(new Date(civilToUtc(date).getTime() + days * DAY_MS));
}

/** Number of days in the inclusive range (same day = 1). */
export function daysBetween(from: CivilDate, to: CivilDate): number {
  return Math.round((civilToUtc(to).getTime() - civilToUtc(from).getTime()) / DAY_MS) + 1;
}

export function startOfMonth(date: CivilDate): CivilDate {
  return `${date.slice(0, 7)}-01`;
}

const todayFormatters = new Map<string, Intl.DateTimeFormat>();

/** Today's calendar date in `timezone` — never the device's timezone. */
export function todayIn(timezone: string, now: Date = new Date()): CivilDate {
  let formatter = todayFormatters.get(timezone);

  if (!formatter) {
    formatter = new Intl.DateTimeFormat('en-US', {
      timeZone: timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    });
    todayFormatters.set(timezone, formatter);
  }

  const parts = formatter.formatToParts(now);
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((candidate) => candidate.type === type)?.value ?? '';

  return `${part('year')}-${part('month')}-${part('day')}`;
}

const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

function parts(date: CivilDate): { year: string; month: string; day: string } {
  const [year = '', month = '', day = ''] = date.split('-');

  return { year, month, day };
}

/** 2026-09-01 → "01/09". Built by hand: no Intl needed for a civil date. */
export function formatDayMonth(date: CivilDate): string {
  const { month, day } = parts(date);

  return `${day}/${month}`;
}

/** 2026-09-01 → "01 set 2026". */
export function formatCivilDate(date: CivilDate): string {
  const { year, month, day } = parts(date);

  return `${day} ${MONTHS[Number(month) - 1]} ${year}`;
}

/** Inclusive range label: "01 – 30 set 2026", "28 ago – 03 set 2026", "15 dez 2025 – 10 jan 2026". */
export function formatCivilRange(from: CivilDate, to: CivilDate): string {
  if (from === to) {
    return formatCivilDate(from);
  }

  const start = parts(from);
  const end = parts(to);

  if (start.year !== end.year) {
    return `${formatCivilDate(from)} – ${formatCivilDate(to)}`;
  }

  if (start.month !== end.month) {
    return `${start.day} ${MONTHS[Number(start.month) - 1]} – ${formatCivilDate(to)}`;
  }

  return `${start.day} – ${formatCivilDate(to)}`;
}
