import { describe, expect, it } from 'vitest'
import type { StockChangedEvent } from '~/types/realtime'
import { formatMoney } from '~/utils/format'
import {
  isLiveDataKey,
  stockChangedNotice,
  toRealtimeStatus,
  transactionRecordedNotice,
  transactionStatusChangedNotice,
} from '~/utils/realtime'

const org = '0199a000-0000-7000-8000-00000000000a'
const other = '0199a000-0000-7000-8000-00000000000b'

describe('isLiveDataKey', () => {
  it.each([
    `dashboard:kpis:${org}`,
    `dashboard:inventory:${org}`,
    `transactions:list:${org}`,
    `transactions:detail:${org}:t1`,
    `analytics:products:${org}`,
    `products:detail:${org}:p1`,
    `customers:list:${org}`,
  ])('refreshes %s', (key) => {
    expect(isLiveDataKey(key, org)).toBe(true)
  })

  it.each([
    `dashboard:kpis:${other}`,
    `insights:period-summary:${org}:2026-09-01:2026-09-30`,
    `api-keys:${org}`,
    'dashboard:kpis:none',
  ])('leaves %s alone', (key) => {
    expect(isLiveDataKey(key, org)).toBe(false)
  })
})

describe('toRealtimeStatus', () => {
  it('maps the Echo connection states', () => {
    expect(toRealtimeStatus('connected')).toBe('live')
    expect(toRealtimeStatus('connecting')).toBe('connecting')
    expect(toRealtimeStatus('reconnecting')).toBe('offline')
    expect(toRealtimeStatus('disconnected')).toBe('offline')
    expect(toRealtimeStatus('failed')).toBe('offline')
  })
})

describe('notices', () => {
  const sale = { id: 't1', total_amount: '1234.50', currency: 'BRL', occurred_at: '2026-10-13T20:00:00.000000Z' }

  it('announces paid sales only', () => {
    expect(transactionRecordedNotice({ ...sale, status: 'paid' }))
      .toEqual({ title: `Nova venda ${formatMoney('1234.50', 'BRL')}`, tone: 'success' })
    expect(transactionRecordedNotice({ ...sale, status: 'pending' })).toBeNull()
  })

  it('announces a pending sale once it is paid', () => {
    expect(transactionStatusChangedNotice({ ...sale, from: 'pending', to: 'paid' }))
      .toEqual({ title: `Venda confirmada ${formatMoney('1234.50', 'BRL')}`, tone: 'success' })
    expect(transactionStatusChangedNotice({ ...sale, from: 'paid', to: 'refunded' })).toBeNull()
  })

  const stock = (status: StockChangedEvent['status'], previous: StockChangedEvent['previous_status'], tracked = true): StockChangedEvent => ({
    product_id: 'p1', sku: 'PB-1', name: 'Cadeira', tracked, quantity: 1, min_quantity: 2, status, previous_status: previous,
  })

  it('announces stock entering low or zero', () => {
    expect(stockChangedNotice(stock('low', 'ok'))).toEqual({ title: 'Estoque baixo: Cadeira', tone: 'info' })
    expect(stockChangedNotice(stock('out', 'low'))).toEqual({ title: 'Estoque zerado: Cadeira', tone: 'info' })
    expect(stockChangedNotice(stock('out', null))).toEqual({ title: 'Estoque zerado: Cadeira', tone: 'info' })
  })

  it('stays quiet on ordinary movements and recoveries', () => {
    expect(stockChangedNotice(stock('low', 'low'))).toBeNull()
    expect(stockChangedNotice(stock('ok', 'low'))).toBeNull()
    expect(stockChangedNotice(stock('low', 'out'))).toBeNull()
    expect(stockChangedNotice(stock(null, 'ok', false))).toBeNull()
  })
})
