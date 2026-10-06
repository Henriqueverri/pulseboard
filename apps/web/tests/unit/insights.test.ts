// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { INSIGHT_DESTINATIONS } from '~/types/insights'
import { ApiError, errorMessage } from '~/utils/api-error'
import {
  caveatLabels,
  destinationRoute,
  formatEvidenceValue,
  periodLabel,
  quotaResetTime,
} from '~/utils/insights'

const period = { query: { period: '7d' }, range: { from: '2026-09-23', to: '2026-09-29' } }

describe('destinationRoute', () => {
  it('keeps the period keys of the URL on Dashboard and Analytics', () => {
    expect(destinationRoute('dashboard', period)).toEqual({ path: '/dashboard', query: { period: '7d' } })
    expect(destinationRoute('analytics.revenue', period)).toEqual({ path: '/analytics/revenue', query: { period: '7d' } })
    expect(destinationRoute('analytics.products', period)).toEqual({ path: '/analytics/products', query: { period: '7d' } })
    expect(destinationRoute('analytics.customers', period)).toEqual({ path: '/analytics/customers', query: { period: '7d' } })
    expect(destinationRoute('analytics.transactions', period)).toEqual({ path: '/analytics/transactions', query: { period: '7d' } })
  })

  it('filters the transactions list by status and the resolved dates', () => {
    expect(destinationRoute('transactions.refunded', period)).toEqual({
      path: '/transactions',
      query: { status: 'refunded', from: '2026-09-23', to: '2026-09-29' },
    })
    expect(destinationRoute('transactions.canceled', period)).toMatchObject({ query: { status: 'canceled' } })
    expect(destinationRoute('transactions.pending', period)).toMatchObject({ query: { status: 'pending' } })
  })

  it('carries a custom range as is', () => {
    const custom = { query: { from: '2026-09-01', to: '2026-09-15' }, range: { from: '2026-09-01', to: '2026-09-15' } }

    expect(destinationRoute('analytics.products', custom)).toEqual({ path: '/analytics/products', query: custom.query })
  })

  it('maps every destination of the API enum', () => {
    for (const destination of INSIGHT_DESTINATIONS) {
      expect(destinationRoute(destination, period)).not.toBeNull()
    }
  })

  it('never builds a link from values outside the enum', () => {
    expect(destinationRoute('https://evil.example.com', period)).toBeNull()
    expect(destinationRoute('/settings/api-keys', period)).toBeNull()
    expect(destinationRoute('transactions.paid', period)).toBeNull()
  })
})

describe('insight presentation', () => {
  it('describes the caveats and skips unknown ones', () => {
    expect(caveatLabels(['no_sales', 'future_caveat', 'partial_period'])).toEqual([
      'Não houve vendas pagas no período.',
      'O período termina hoje ou depois: os números ainda podem mudar até o fim do dia.',
    ])
  })

  it('formats evidence values by their format', () => {
    expect(formatEvidenceValue({ format: 'money', value: '96962.20' }, 'BRL')).toMatch(/R\$\s96\.962,20/)
    expect(formatEvidenceValue({ format: 'count', value: 1210 }, 'BRL')).toBe('1.210')
    expect(formatEvidenceValue({ format: 'money', value: null }, 'BRL')).toBe('—')
  })

  it('names the period for accessible link names', () => {
    expect(periodLabel('30d', { from: '2026-08-31', to: '2026-09-29' })).toBe('últimos 30 dias')
    expect(periodLabel('custom', { from: '2026-08-01', to: '2026-08-30' })).toBe('01 – 30 ago 2026')
  })

  it('shows when the quota frees up in the organization timezone', () => {
    const now = new Date('2026-09-29T15:00:00Z')

    expect(quotaResetTime(9 * 3600, 'America/Sao_Paulo', now)).toBe('21:00')
  })
})

describe('insights error messages', () => {
  it('uses the stable code instead of the HTTP status', () => {
    expect(errorMessage(new ApiError(503, { message: 'x', code: 'ai_disabled' }))).toBe('Os insights estão indisponíveis no momento.')
    expect(errorMessage(new ApiError(403, { message: 'x', code: 'ai_not_enabled' }))).toBe('Os insights não estão ativados nesta organização.')
    expect(errorMessage(new ApiError(429, { message: 'x', code: 'ai_quota_exceeded' }))).toMatch(/cota diária/)
    expect(errorMessage(new ApiError(503, { message: 'x', code: 'ai_provider_unavailable' }))).toMatch(/serviço de IA está indisponível/)
    expect(errorMessage(new ApiError(504, { message: 'x', code: 'ai_timeout' }))).toMatch(/demorou demais/)
    expect(errorMessage(new ApiError(502, { message: 'x', code: 'ai_invalid_output' }))).toMatch(/não passou na validação/)
  })

  it('keeps the throttle message for the insights rate limit', () => {
    expect(errorMessage(new ApiError(429, { message: 'Too many requests.', code: 'rate_limited' }, undefined, { retryAfter: 20 })))
      .toBe('Muitas tentativas. Tente novamente em 20 segundos.')
  })
})
