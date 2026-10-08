import { granularityFor, presetRange } from '../period';

const NOW = new Date('2026-09-01T02:59:59Z');

describe('presetRange', () => {
  it('computes every preset in São Paulo, where it is still 31/08', () => {
    expect(presetRange('today', 'America/Sao_Paulo', NOW)).toEqual({ from: '2026-08-31', to: '2026-08-31' });
    expect(presetRange('7d', 'America/Sao_Paulo', NOW)).toEqual({ from: '2026-08-25', to: '2026-08-31' });
    expect(presetRange('30d', 'America/Sao_Paulo', NOW)).toEqual({ from: '2026-08-02', to: '2026-08-31' });
    expect(presetRange('month', 'America/Sao_Paulo', NOW)).toEqual({ from: '2026-08-01', to: '2026-08-31' });
  });

  it('computes the same instant in UTC and Tokyo, where September already started', () => {
    expect(presetRange('month', 'UTC', NOW)).toEqual({ from: '2026-09-01', to: '2026-09-01' });
    expect(presetRange('30d', 'Asia/Tokyo', NOW)).toEqual({ from: '2026-08-03', to: '2026-09-01' });
  });

  it('computes New York ranges around the DST change', () => {
    const afterDst = new Date('2026-11-02T04:30:00Z'); // 01/11 23:30 EST

    expect(presetRange('today', 'America/New_York', afterDst)).toEqual({ from: '2026-11-01', to: '2026-11-01' });
    expect(presetRange('7d', 'America/New_York', afterDst)).toEqual({ from: '2026-10-26', to: '2026-11-01' });
  });
});

describe('granularityFor', () => {
  it('uses daily buckets up to 31 days and weekly beyond', () => {
    expect(granularityFor({ from: '2026-08-01', to: '2026-08-31' })).toBe('day');
    expect(granularityFor({ from: '2026-08-01', to: '2026-09-01' })).toBe('week');
  });
});
