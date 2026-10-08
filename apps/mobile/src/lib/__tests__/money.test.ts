import { decimalToNumber, formatInteger, formatMoney } from '../money';

describe('money', () => {
  it('formats API decimal strings as currency', () => {
    expect(formatMoney('98301.40', 'BRL')).toBe('R$ 98.301,40');
    expect(formatMoney('0.00', 'BRL')).toBe('R$ 0,00');
    expect(formatMoney('1250.5', 'USD')).toBe('US$ 1.250,50');
  });

  it('shows a dash for null or invalid values', () => {
    expect(formatMoney(null, 'BRL')).toBe('—');
    expect(formatMoney('abc', 'BRL')).toBe('—');
    expect(decimalToNumber('')).toBeNull();
  });

  it('formats integers with thousand separators', () => {
    expect(formatInteger(12345)).toBe('12.345');
    expect(formatInteger(null)).toBe('—');
  });
});
