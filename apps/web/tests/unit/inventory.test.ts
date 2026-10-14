// @vitest-environment node
import { describe, expect, it } from 'vitest'
import type { InventoryItem, StockStatus } from '~/types/analytics'
import { formatDaysOfCover, formatUnits, itemsNeedingAttention } from '~/utils/inventory'

describe('formatDaysOfCover', () => {
  it('says how long the balance lasts', () => {
    expect(formatDaysOfCover(1.5, 28)).toBe('Dura cerca de 1,5 dia')
    expect(formatDaysOfCover(2, 28)).toBe('Dura cerca de 2 dias')
    expect(formatDaysOfCover(24.25, 28)).toBe('Dura cerca de 24,3 dias')
  })

  it('covers the edges: no sales, no balance, less than a day', () => {
    expect(formatDaysOfCover(null, 28)).toBe('Sem vendas nos últimos 28 dias')
    expect(formatDaysOfCover(0, 28)).toBe('Sem saldo')
    expect(formatDaysOfCover(0.4, 28)).toBe('Acaba hoje')
  })
})

describe('formatUnits', () => {
  it('groups thousands', () => {
    expect(formatUnits(1200)).toBe('1.200 un.')
  })
})

describe('itemsNeedingAttention', () => {
  const item = (id: string, status: StockStatus): InventoryItem => ({
    product: { id, name: id, sku: null },
    quantity: 1,
    min_quantity: 1,
    status,
    units_sold: 0,
    average_daily_units: 0,
    days_of_cover: null,
    updated_at: null,
  })

  it('keeps the API order and drops the products above the minimum', () => {
    expect(itemsNeedingAttention([item('a', 'out'), item('b', 'ok'), item('c', 'low')]).map(i => i.product.id)).toEqual(['a', 'c'])
  })
})
