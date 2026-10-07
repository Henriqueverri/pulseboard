const LOCALE = 'pt-BR';
export const EMPTY_VALUE = '—';

const formatters = new Map<string, Intl.NumberFormat>();

function currencyFormatter(currency: string): Intl.NumberFormat {
  let formatter = formatters.get(currency);

  if (!formatter) {
    formatter = new Intl.NumberFormat(LOCALE, {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
    formatters.set(currency, formatter);
  }

  return formatter;
}

const integerFormatter = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 });

/**
 * Decimal string from the API ("98301.40") as a number — only for display and the chart:
 * the API string stays the source of truth.
 */
export function decimalToNumber(value: string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const number = Number(value);

  return Number.isFinite(number) ? number : null;
}

/** "98301.40" → "R$ 98.301,40"; `null` (e.g. average order value without orders) → "—". */
export function formatMoney(value: string | null | undefined, currency: string): string {
  const number = decimalToNumber(value);

  // Intl separates the symbol with a no-break space; a regular space keeps texts predictable.
  return number === null ? EMPTY_VALUE : currencyFormatter(currency).format(number).replace(/\u00a0/g, ' ');
}

export function formatInteger(value: number | null | undefined): string {
  return value === null || value === undefined ? EMPTY_VALUE : integerFormatter.format(value);
}
