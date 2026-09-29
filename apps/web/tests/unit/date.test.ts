// @vitest-environment node
import { describe, expect, it } from 'vitest'
import {
  addDays,
  daysBetween,
  endOfPreviousMonth,
  formatCivilDate,
  formatCivilRange,
  formatDateTime,
  isCivilDate,
  startOfMonth,
  todayIn,
} from '~/utils/date'

describe('civil dates', () => {
  it('validates calendar dates', () => {
    expect(isCivilDate('2026-09-01')).toBe(true)
    expect(isCivilDate('2026-02-30')).toBe(false)
    expect(isCivilDate('2026-9-1')).toBe(false)
    expect(isCivilDate(null)).toBe(false)
  })

  it('does calendar arithmetic across months and leap years', () => {
    expect(addDays('2026-09-01', -1)).toBe('2026-08-31')
    expect(addDays('2028-02-28', 1)).toBe('2028-02-29')
    expect(daysBetween('2026-09-01', '2026-09-30')).toBe(30)
    expect(daysBetween('2026-09-01', '2026-09-01')).toBe(1)
    expect(startOfMonth('2026-09-17')).toBe('2026-09-01')
    expect(endOfPreviousMonth('2026-03-10')).toBe('2026-02-28')
  })

  it('formats civil dates without shifting the day', () => {
    expect(formatCivilDate('2026-09-01')).toBe('01/09/2026')
    expect(formatCivilDate('2026-09-01', 'medium')).toBe('01 set 2026')
    expect(formatCivilDate('2026-09-01', 'dayMonth')).toBe('01 set')
    expect(formatCivilDate('2026-09-01', 'monthYear')).toBe('set 2026')
  })

  it('formats inclusive ranges compactly', () => {
    expect(formatCivilRange('2026-09-01', '2026-09-30')).toBe('01 – 30 set 2026')
    expect(formatCivilRange('2026-08-28', '2026-09-03')).toBe('28 ago – 03 set 2026')
    expect(formatCivilRange('2025-12-15', '2026-01-10')).toBe('15 dez 2025 – 10 jan 2026')
    expect(formatCivilRange('2026-09-01', '2026-09-01')).toBe('01 set 2026')
  })
})

describe('organization timezone', () => {
  it('computes today in the organization timezone, not UTC', () => {
    const lateNightInSaoPaulo = new Date('2026-09-02T02:30:00Z')

    expect(todayIn('America/Sao_Paulo', lateNightInSaoPaulo)).toBe('2026-09-01')
    expect(todayIn('Europe/Lisbon', lateNightInSaoPaulo)).toBe('2026-09-02')
  })

  it('formats UTC instants in the organization timezone', () => {
    expect(formatDateTime('2026-09-02T02:30:00.000000Z', 'America/Sao_Paulo')).toBe('01/09/2026 23:30')
    expect(formatDateTime('2026-09-02T02:30:00.000000Z', 'America/Sao_Paulo', { time: false })).toBe('01/09/2026')
    expect(formatDateTime(null, 'America/Sao_Paulo')).toBe('—')
  })
})
