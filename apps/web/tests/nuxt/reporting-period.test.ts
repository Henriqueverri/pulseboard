import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Router } from 'vue-router'
import { useAuthStore } from '~/stores/auth'
import { previousRange, useReportingPeriod } from '~/composables/useReportingPeriod'

describe('useReportingPeriod', () => {
  let router: Router

  beforeEach(async () => {
    // 01:00 UTC on Sep 30 is still Sep 29 in São Paulo: presets must end on the organization's today.
    vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-30T01:00:00Z') })
    useAuthStore().setSession({ id: 'u1', name: 'Owner', email: 'owner@example.com' }, {
      id: '0199a000-0000-7000-8000-00000000000a', name: 'Loja', slug: 'loja', currency: 'BRL', timezone: 'America/Sao_Paulo', insights: { available: false, enabled: false }, role: 'owner',
    })
    router = useRouter()
    await router.replace({ path: '/dashboard', query: {} })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('defaults to the last 30 days ending today in the organization timezone', () => {
    const period = useReportingPeriod()

    expect(period.preset.value).toBe('30d')
    expect(period.params.value).toEqual({ from: '2026-08-31', to: '2026-09-29' })
    expect(period.granularity.value).toBe('day')
  })

  it('reads presets and custom ranges from the URL', async () => {
    const period = useReportingPeriod()

    await router.replace({ query: { period: '12m' } })
    expect(period.preset.value).toBe('12m')
    expect(period.params.value).toEqual({ from: '2025-09-30', to: '2026-09-29' })
    expect(period.granularity.value).toBe('month')

    await router.replace({ query: { from: '2026-09-01', to: '2026-09-15', granularity: 'week' } })
    expect(period.preset.value).toBe('custom')
    expect(period.params.value).toEqual({ from: '2026-09-01', to: '2026-09-15' })
    expect(period.granularity.value).toBe('week')
  })

  it('falls back to the default for invalid URL values instead of requesting them', async () => {
    const period = useReportingPeriod()

    for (const query of [
      { from: '2026-09-15', to: '2026-09-01' },
      { from: '2025-01-01', to: '2026-01-02' },
      { from: '2026-09-01' },
      { from: '2026-02-30', to: '2026-03-01' },
      { period: 'forever', granularity: 'hour' },
    ]) {
      await router.replace({ query })
      expect(period.preset.value).toBe('30d')
      expect(period.params.value).toEqual({ from: '2026-08-31', to: '2026-09-29' })
      expect(period.granularity.value).toBe('day')
    }
  })

  it('accepts exactly 366 days', async () => {
    const period = useReportingPeriod()

    await router.replace({ query: { from: '2025-09-29', to: '2026-09-29' } })

    expect(period.preset.value).toBe('custom')
    expect(period.days.value).toBe(366)
  })

  it('validates custom ranges before touching the URL', async () => {
    const period = useReportingPeriod()

    expect(await period.setCustomRange('2026-09-15', '2026-09-01')).toBe('A data final deve ser igual ou posterior à inicial.')
    expect(await period.setCustomRange('2025-01-01', '2026-01-02')).toBe('O período pode ter no máximo 366 dias.')
    expect(router.currentRoute.value.query).toEqual({})

    expect(await period.setCustomRange('2026-09-01', '2026-09-15')).toBeNull()
    expect(router.currentRoute.value.query).toEqual({ from: '2026-09-01', to: '2026-09-15' })
  })

  it('keeps unrelated query keys and drops a manual granularity when the period changes', async () => {
    const period = useReportingPeriod()
    await router.replace({ query: { sort: 'units_sold', granularity: 'month' } })

    await period.setPreset('7d')
    expect(router.currentRoute.value.query).toEqual({ sort: 'units_sold', period: '7d' })

    await period.setPreset('30d')
    expect(router.currentRoute.value.query).toEqual({ sort: 'units_sold' })
  })

  it('omits the suggested granularity from the URL', async () => {
    const period = useReportingPeriod()

    await period.setGranularity('week')
    expect(router.currentRoute.value.query).toEqual({ granularity: 'week' })

    await period.setGranularity('day')
    expect(router.currentRoute.value.query).toEqual({})
  })

  it('carries only the period keys to other pages', async () => {
    const period = useReportingPeriod()
    await router.replace({ query: { period: '90d', granularity: 'month', sort: 'orders' } })

    expect(period.query.value).toEqual({ period: '90d' })
  })

  it('computes the previous period like the API', () => {
    expect(previousRange({ from: '2026-08-31', to: '2026-09-29' })).toEqual({ from: '2026-08-01', to: '2026-08-30' })
  })
})
