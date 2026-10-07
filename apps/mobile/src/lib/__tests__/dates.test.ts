import { addDays, daysBetween, formatCivilRange, formatDayMonth, startOfMonth, todayIn } from '../dates';

describe('todayIn', () => {
  it('uses the organization timezone, not the device one (API doc example)', () => {
    const instant = new Date('2026-09-01T02:59:59Z');

    expect(todayIn('America/Sao_Paulo', instant)).toBe('2026-08-31');
    expect(todayIn('UTC', instant)).toBe('2026-09-01');
    expect(todayIn('Asia/Tokyo', instant)).toBe('2026-09-01');
  });

  it('turns the day at local midnight', () => {
    expect(todayIn('America/Sao_Paulo', new Date('2026-09-01T03:00:00Z'))).toBe('2026-09-01');
    expect(todayIn('Asia/Tokyo', new Date('2026-08-31T14:59:59Z'))).toBe('2026-08-31');
    expect(todayIn('Asia/Tokyo', new Date('2026-08-31T15:00:00Z'))).toBe('2026-09-01');
  });

  it('follows daylight saving time in America/New_York', () => {
    // EDT (UTC−4) until 2026-11-01 06:00Z, EST (UTC−5) after.
    expect(todayIn('America/New_York', new Date('2026-07-01T03:59:59Z'))).toBe('2026-06-30');
    expect(todayIn('America/New_York', new Date('2026-07-01T04:00:00Z'))).toBe('2026-07-01');
    expect(todayIn('America/New_York', new Date('2026-12-01T04:59:59Z'))).toBe('2026-11-30');
    expect(todayIn('America/New_York', new Date('2026-12-01T05:00:00Z'))).toBe('2026-12-01');
  });

  it('turns the year', () => {
    expect(todayIn('America/Sao_Paulo', new Date('2027-01-01T02:00:00Z'))).toBe('2026-12-31');
    expect(todayIn('UTC', new Date('2027-01-01T02:00:00Z'))).toBe('2027-01-01');
  });
});

describe('civil date arithmetic', () => {
  it('adds days across months, years and leap days', () => {
    expect(addDays('2026-09-01', -1)).toBe('2026-08-31');
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(addDays('2028-03-01', -1)).toBe('2028-02-29');
  });

  it('counts inclusive days', () => {
    expect(daysBetween('2026-09-01', '2026-09-01')).toBe(1);
    expect(daysBetween('2026-09-01', '2026-09-30')).toBe(30);
  });

  it('finds the start of the month', () => {
    expect(startOfMonth('2026-09-17')).toBe('2026-09-01');
  });
});

describe('formatting', () => {
  it('formats days and ranges without shifting the date', () => {
    expect(formatDayMonth('2026-09-01')).toBe('01/09');
    expect(formatCivilRange('2026-09-01', '2026-09-30')).toBe('01 – 30 set 2026');
    expect(formatCivilRange('2026-08-28', '2026-09-03')).toBe('28 ago – 03 set 2026');
    expect(formatCivilRange('2025-12-15', '2026-01-10')).toBe('15 dez 2025 – 10 jan 2026');
    expect(formatCivilRange('2026-09-01', '2026-09-01')).toBe('01 set 2026');
  });
});
