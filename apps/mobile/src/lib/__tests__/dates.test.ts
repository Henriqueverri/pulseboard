import { addDays, daysBetween, formatCivilRange, formatDateTime, formatDayMonth, startOfMonth, todayIn } from '../dates';

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

describe('formatDateTime', () => {
  it('shows the instant in the organization timezone, 24-hour clock', () => {
    expect(formatDateTime('2026-09-01T02:59:59Z', 'America/Sao_Paulo')).toBe('31/08/2026 23:59');
    expect(formatDateTime('2026-09-01T02:59:59Z', 'UTC')).toBe('01/09/2026 02:59');
    expect(formatDateTime('2026-09-15T14:32:00.000000Z', 'Asia/Tokyo')).toBe('15/09/2026 23:32');
  });

  it('writes midnight as 00, not 24', () => {
    expect(formatDateTime('2026-09-01T03:00:00Z', 'America/Sao_Paulo')).toBe('01/09/2026 00:00');
  });

  it('follows daylight saving time', () => {
    expect(formatDateTime('2026-07-01T16:00:00Z', 'America/New_York')).toBe('01/07/2026 12:00');
    expect(formatDateTime('2026-12-01T16:00:00Z', 'America/New_York')).toBe('01/12/2026 11:00');
  });

  it('shows a dash for missing or invalid values', () => {
    expect(formatDateTime(null, 'UTC')).toBe('—');
    expect(formatDateTime('not a date', 'UTC')).toBe('—');
  });
});
