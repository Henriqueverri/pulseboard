const LOCALE = 'pt-BR'
export const EMPTY_VALUE = '—'

const currencyFormatters = new Map<string, Intl.NumberFormat>()

function currencyFormatter(currency: string, compact: boolean): Intl.NumberFormat {
  const key = `${currency}:${compact}`
  let formatter = currencyFormatters.get(key)

  if (!formatter) {
    formatter = new Intl.NumberFormat(LOCALE, {
      style: 'currency',
      currency,
      ...(compact
        ? { notation: 'compact', maximumFractionDigits: 1 }
        : { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
    })
    currencyFormatters.set(key, formatter)
  }

  return formatter
}

const integerFormatter = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 })
const compactFormatter = new Intl.NumberFormat(LOCALE, { notation: 'compact', maximumFractionDigits: 1 })
const percentFormatter = new Intl.NumberFormat(LOCALE, { minimumFractionDigits: 1, maximumFractionDigits: 1 })

function toNumber(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const number = typeof value === 'number' ? value : Number(value)

  return Number.isFinite(number) ? number : null
}

/**
 * Formats a decimal string from the API ("98301.40") as currency (R$ 98.301,40).
 * Presentation only: the API string stays the source of truth.
 */
export function formatMoney(
  value: string | number | null | undefined,
  currency = 'BRL',
  options: { compact?: boolean } = {},
): string {
  const number = toNumber(value)

  return number === null ? EMPTY_VALUE : currencyFormatter(currency, options.compact ?? false).format(number)
}

export function formatInteger(value: number | string | null | undefined): string {
  const number = toNumber(value)

  return number === null ? EMPTY_VALUE : integerFormatter.format(number)
}

export function formatCompactNumber(value: number | string | null | undefined): string {
  const number = toNumber(value)

  return number === null ? EMPTY_VALUE : compactFormatter.format(number)
}

/** Formats a percentage already expressed in percent units (65.3 → "65,3%"). */
export function formatPercent(value: number | null | undefined): string {
  return value === null || value === undefined ? EMPTY_VALUE : `${percentFormatter.format(value)}%`
}

/** Signed percent variation (22.5 → "+22,5%", -3 → "−3,0%"). */
export function formatChange(change: number): string {
  if (change === 0) {
    return `${percentFormatter.format(0)}%`
  }

  const sign = change > 0 ? '+' : '−'

  return `${sign}${percentFormatter.format(Math.abs(change))}%`
}

/** Converts a user-typed amount ("1.234,5" or "1234.5") to the API decimal string ("1234.50"). */
export function toMoneyString(input: string): string | null {
  const trimmed = input.trim().replace(/[R$\s]/g, '')

  if (trimmed === '') {
    return null
  }

  const normalized = trimmed.includes(',')
    ? trimmed.replace(/\./g, '').replace(',', '.')
    : trimmed

  if (!/^\d+(\.\d{0,2})?$/.test(normalized)) {
    return null
  }

  return Number(normalized).toFixed(2)
}

/** API decimal string ("1234.50") to the editable pt-BR form ("1234,50"). */
export function fromMoneyString(value: string | null | undefined): string {
  if (!value) {
    return ''
  }

  return value.replace('.', ',')
}

export function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)

  if (parts.length === 0) {
    return '?'
  }

  const first = parts[0]!.charAt(0)
  const last = parts.length > 1 ? parts[parts.length - 1]!.charAt(0) : ''

  return `${first}${last}`.toUpperCase()
}
