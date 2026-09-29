// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { describeChange } from '~/utils/comparison'

describe('describeChange', () => {
  it('describes growth and decline', () => {
    expect(describeChange({ value: '1250.00', previous: '1000.00', change: 25.0 })).toMatchObject({
      trend: 'up', tone: 'positive', text: '+25,0%',
    })
    expect(describeChange({ value: 6, previous: 9, change: -33.3 })).toMatchObject({
      trend: 'down', tone: 'negative', text: '−33,3%',
    })
  })

  it('treats 0.0 as stable', () => {
    expect(describeChange({ value: 0, previous: 0, change: 0.0 })).toMatchObject({ trend: 'flat', tone: 'neutral' })
    expect(describeChange({ value: 50.0, previous: 50.0, change: 0.0 })).toMatchObject({ trend: 'flat' })
  })

  it('flags a new value when the previous period was zero', () => {
    expect(describeChange({ value: '480.00', previous: '0.00', change: null })).toMatchObject({
      trend: 'new', text: 'Novo',
    })
    expect(describeChange({ value: 1, previous: 0, change: null })).toMatchObject({ trend: 'new' })
  })

  it('shows no comparison for undefined values', () => {
    expect(describeChange({ value: null, previous: '120.00', change: null })).toMatchObject({ trend: 'none', text: '—' })
    expect(describeChange({ value: '90.00', previous: null, change: null })).toMatchObject({ trend: 'none' })
    expect(describeChange({ value: null, previous: null, change: null })).toMatchObject({ trend: 'none' })
  })

  it('inverts the tone for negative polarity metrics', () => {
    expect(describeChange({ value: 4, previous: 2, change: 100.0 }, 'negative')).toMatchObject({ trend: 'up', tone: 'negative' })
    expect(describeChange({ value: 0, previous: 1, change: -100.0 }, 'negative')).toMatchObject({ trend: 'down', tone: 'positive' })
  })

  it('keeps a neutral tone for neutral polarity', () => {
    expect(describeChange({ value: 25.0, previous: 20.0, change: 25.0 }, 'neutral')).toMatchObject({ trend: 'up', tone: 'neutral' })
  })
})
