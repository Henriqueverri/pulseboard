import { describe, expect, it } from 'vitest'
import type { ProductRankingRow, RevenueBucket } from '~/types/analytics'
import {
  bucketAxisLabel,
  bucketRangeLabel,
  customerRankingItems,
  isPartialBucket,
  productRankingItems,
  shareOf,
} from '~/utils/analytics'

const bucket = (from: string, to: string, start = from): RevenueBucket =>
  ({ bucket: start, from, to, revenue: '100.00', orders: 1 })

describe('revenue buckets', () => {
  it('flags weeks and months clipped by the period edges', () => {
    // Week starting Monday Aug 31, clipped to the period start (Sep 1).
    expect(isPartialBucket(bucket('2026-09-01', '2026-09-06', '2026-08-31'), 'week')).toBe(true)
    expect(isPartialBucket(bucket('2026-09-07', '2026-09-13'), 'week')).toBe(false)
    expect(isPartialBucket(bucket('2026-09-01', '2026-09-29'), 'month')).toBe(true)
    expect(isPartialBucket(bucket('2026-02-01', '2026-02-28'), 'month')).toBe(false)
    expect(isPartialBucket(bucket('2026-09-15', '2026-09-15'), 'day')).toBe(false)
  })

  it('labels the exact days covered, marking partial buckets', () => {
    expect(bucketRangeLabel(bucket('2026-09-15', '2026-09-15'), 'day')).toBe('15 set 2026')
    expect(bucketRangeLabel(bucket('2026-09-01', '2026-09-06', '2026-08-31'), 'week')).toBe('01 – 06 set 2026 (parcial)')
    expect(bucketRangeLabel(bucket('2026-09-07', '2026-09-13'), 'week')).toBe('07 – 13 set 2026')
    expect(bucketRangeLabel(bucket('2026-08-01', '2026-08-31'), 'month')).toBe('ago 2026')
    expect(bucketRangeLabel(bucket('2026-09-01', '2026-09-29'), 'month')).toBe('01 – 29 set 2026 (parcial)')
  })

  it('uses short axis labels', () => {
    expect(bucketAxisLabel(bucket('2026-09-01', '2026-09-06', '2026-08-31'), 'week')).toBe('01 set')
    expect(bucketAxisLabel(bucket('2026-08-01', '2026-08-31'), 'month')).toBe('ago 2026')
  })
})

describe('rankings', () => {
  const rows: ProductRankingRow[] = [
    {
      rank: 1,
      product: { id: 'p1', name: 'Cadeira', sku: 'CAD-1', status: 'active', is_deleted: false },
      revenue: { value: '200.00', previous: '100.00', change: 100 },
      units_sold: { value: 2, previous: 1, change: 100 },
    },
    {
      rank: 2,
      product: { id: 'p2', name: 'Mesa antiga', sku: null, status: 'inactive', is_deleted: true },
      revenue: { value: '50.00', previous: '0.00', change: null },
      units_sold: { value: 4, previous: 0, change: null },
    },
  ]

  it('keeps the API order and links only to existing products', () => {
    const items = productRankingItems(rows, 'BRL')

    expect(items.map(item => item.rank)).toEqual([1, 2])
    expect(items[0]).toMatchObject({ label: 'Cadeira', to: '/products/p1', removed: false, share: 1 })
    expect(items[0]!.value).toMatch(/R\$\s200,00/)
    expect(items[1]).toMatchObject({ label: 'Mesa antiga', to: null, removed: true, share: 0.25 })
  })

  it('measures bars by the chosen metric', () => {
    const items = productRankingItems(rows, 'BRL', 'units_sold')

    expect(items[0]).toMatchObject({ value: '2 un.', share: 0.5 })
    expect(items[1]).toMatchObject({ value: '4 un.', share: 1 })
    expect(items[1]!.change).toEqual(rows[1]!.units_sold)
  })

  it('builds customer items with the email as sublabel', () => {
    const [item] = customerRankingItems([{
      rank: 1,
      customer: { id: 'c1', name: 'Maria', email: 'maria@example.com', is_deleted: true },
      revenue: { value: '10.00', previous: '5.00', change: 100 },
      orders: { value: 1, previous: 1, change: 0 },
    }], 'BRL')

    expect(item).toMatchObject({ sublabel: 'maria@example.com', to: null, removed: true })
  })

  it('never divides by a zero leader', () => {
    expect(shareOf('0.00', '0.00')).toBe(0)
    expect(shareOf('25.00', '100.00')).toBe(0.25)
  })
})
