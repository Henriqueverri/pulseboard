import type { CivilDate } from '~/types/api'
import type { CustomerRankingRow, ProductRankingRow, RankingItem, RevenueBucket } from '~/types/analytics'
import { addDays, daysBetween, formatCivilDate, formatCivilRange, startOfMonth } from './date'
import { formatInteger, formatMoney } from './format'
import type { Granularity } from './period'

/** Bar length relative to the largest value. Presentation only: ranks come from the API. */
export function shareOf(value: number | string, max: number | string): number {
  const top = Number(max)

  return top > 0 ? Number(value) / top : 0
}

function largest(values: Array<number | string>): number {
  return values.reduce<number>((max, value) => Math.max(max, Number(value)), 0)
}

function endOfMonth(date: CivilDate): CivilDate {
  const [year, month] = date.split('-').map(Number) as [number, number]
  const next = month === 12 ? `${year + 1}-01-01` : `${year}-${String(month + 1).padStart(2, '0')}-01`

  return addDays(next, -1)
}

/** A bucket clipped by the period edges (first/last week or month) covers fewer days. */
export function isPartialBucket(bucket: RevenueBucket, granularity: Granularity): boolean {
  switch (granularity) {
    case 'day':
      return false
    case 'week':
      return daysBetween(bucket.from, bucket.to) < 7
    case 'month':
      return bucket.from !== startOfMonth(bucket.from) || bucket.to !== endOfMonth(bucket.from)
  }
}

/** Short x-axis label: "15 set", week start "01 set", or "set 2026". */
export function bucketAxisLabel(bucket: RevenueBucket, granularity: Granularity): string {
  return granularity === 'month'
    ? formatCivilDate(bucket.from, 'monthYear')
    : formatCivilDate(bucket.from, 'dayMonth')
}

/** Full label with the exact days covered: "15 set 2026", "01 – 06 set 2026 (parcial)", "set 2026". */
export function bucketRangeLabel(bucket: RevenueBucket, granularity: Granularity): string {
  if (granularity === 'day') {
    return formatCivilDate(bucket.from, 'medium')
  }

  const partial = isPartialBucket(bucket, granularity)

  if (granularity === 'month' && !partial) {
    return formatCivilDate(bucket.from, 'monthYear')
  }

  return `${formatCivilRange(bucket.from, bucket.to)}${partial ? ' (parcial)' : ''}`
}

export type ProductRankingMetric = 'revenue' | 'units_sold'

export function productRankingItems(
  rows: ProductRankingRow[],
  currency: string,
  metric: ProductRankingMetric = 'revenue',
): RankingItem[] {
  const max = largest(rows.map(row => row[metric].value))

  return rows.map(row => ({
    key: row.product.id,
    rank: row.rank,
    label: row.product.name,
    sublabel: row.product.sku,
    to: row.product.is_deleted ? null : `/products/${row.product.id}`,
    removed: row.product.is_deleted,
    value: metric === 'revenue' ? formatMoney(row.revenue.value, currency) : `${formatInteger(row.units_sold.value)} un.`,
    share: shareOf(row[metric].value, max),
    change: row[metric],
  }))
}

export type CustomerRankingMetric = 'revenue' | 'orders'

export function customerRankingItems(
  rows: CustomerRankingRow[],
  currency: string,
  metric: CustomerRankingMetric = 'revenue',
): RankingItem[] {
  const max = largest(rows.map(row => row[metric].value))

  return rows.map(row => ({
    key: row.customer.id,
    rank: row.rank,
    label: row.customer.name,
    sublabel: row.customer.email,
    to: row.customer.is_deleted ? null : `/customers/${row.customer.id}`,
    removed: row.customer.is_deleted,
    value: metric === 'revenue' ? formatMoney(row.revenue.value, currency) : `${formatInteger(row.orders.value)} pedidos`,
    share: shareOf(row[metric].value, max),
    change: row[metric],
  }))
}
