// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { resolvePreset, suggestGranularity, validateRange } from '~/utils/period'

describe('resolvePreset', () => {
  const today = '2026-09-29'

  it('builds inclusive ranges ending today', () => {
    expect(resolvePreset('7d', today)).toEqual({ from: '2026-09-23', to: today })
    expect(resolvePreset('30d', today)).toEqual({ from: '2026-08-31', to: today })
    expect(resolvePreset('90d', today)).toEqual({ from: '2026-07-02', to: today })
    expect(resolvePreset('12m', today)).toEqual({ from: '2025-09-30', to: today })
  })

  it('handles calendar month presets', () => {
    expect(resolvePreset('mtd', today)).toEqual({ from: '2026-09-01', to: today })
    expect(resolvePreset('last-month', today)).toEqual({ from: '2026-08-01', to: '2026-08-31' })
    expect(resolvePreset('last-month', '2026-01-15')).toEqual({ from: '2025-12-01', to: '2025-12-31' })
  })

  it('never exceeds the API limit', () => {
    for (const preset of ['7d', '30d', '90d', '12m', 'mtd', 'last-month'] as const) {
      const range = resolvePreset(preset, today)
      expect(validateRange(range.from, range.to)).toBeNull()
    }
  })
})

describe('validateRange', () => {
  it('mirrors the API rules', () => {
    expect(validateRange('2026-09-01', '2026-09-30')).toBeNull()
    expect(validateRange('2026-09-30', '2026-09-01')).toMatch(/posterior/)
    expect(validateRange('2025-01-01', '2026-01-02')).toMatch(/366/)
    expect(validateRange('2025-01-01', '2026-01-01')).toBeNull()
    expect(validateRange('x', '2026-01-01')).toMatch(/válidas/)
  })
})

describe('suggestGranularity', () => {
  it('keeps charts readable', () => {
    expect(suggestGranularity(7)).toBe('day')
    expect(suggestGranularity(30)).toBe('day')
    expect(suggestGranularity(90)).toBe('week')
    expect(suggestGranularity(365)).toBe('month')
  })
})
