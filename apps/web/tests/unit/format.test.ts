// @vitest-environment node
import { describe, expect, it } from 'vitest'
import {
  formatChange,
  formatCompactNumber,
  formatInteger,
  formatMoney,
  formatPercent,
  fromMoneyString,
  initials,
  toMoneyString,
} from '~/utils/format'

const normalize = (text: string) => text.replace(/\s/g, ' ')

describe('formatMoney', () => {
  it('formats API decimal strings as BRL', () => {
    expect(normalize(formatMoney('98301.40'))).toBe('R$ 98.301,40')
    expect(normalize(formatMoney('0.00'))).toBe('R$ 0,00')
  })

  it('uses the given currency', () => {
    expect(normalize(formatMoney('10.00', 'USD'))).toBe('US$ 10,00')
  })

  it('returns a dash for null, undefined and invalid values', () => {
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney(undefined)).toBe('—')
    expect(formatMoney('abc')).toBe('—')
  })

  it('supports compact notation for chart axes', () => {
    expect(normalize(formatMoney('98301.40', 'BRL', { compact: true }))).toBe('R$ 98,3 mil')
  })
})

describe('number formatting', () => {
  it('formats integers with pt-BR grouping', () => {
    expect(formatInteger(12345)).toBe('12.345')
    expect(formatInteger(null)).toBe('—')
  })

  it('formats compact numbers', () => {
    expect(normalize(formatCompactNumber(1500))).toBe('1,5 mil')
  })

  it('formats percentages with one decimal', () => {
    expect(formatPercent(65.3)).toBe('65,3%')
    expect(formatPercent(0)).toBe('0,0%')
    expect(formatPercent(null)).toBe('—')
  })

  it('formats signed changes', () => {
    expect(formatChange(22.5)).toBe('+22,5%')
    expect(formatChange(-3)).toBe('−3,0%')
    expect(formatChange(0)).toBe('0,0%')
  })
})

describe('money input conversion', () => {
  it('parses pt-BR and dot decimal input to API strings', () => {
    expect(toMoneyString('1.234,5')).toBe('1234.50')
    expect(toMoneyString('1234.5')).toBe('1234.50')
    expect(toMoneyString('R$ 49,90')).toBe('49.90')
    expect(toMoneyString('10')).toBe('10.00')
  })

  it('rejects empty and invalid input', () => {
    expect(toMoneyString('')).toBeNull()
    expect(toMoneyString('12,345')).toBeNull()
    expect(toMoneyString('abc')).toBeNull()
    expect(toMoneyString('-5')).toBeNull()
  })

  it('converts API strings back to editable text', () => {
    expect(fromMoneyString('1234.50')).toBe('1234,50')
    expect(fromMoneyString(null)).toBe('')
  })
})

describe('initials', () => {
  it('uses first and last names', () => {
    expect(initials('Maria da Silva')).toBe('MS')
    expect(initials('joão')).toBe('J')
    expect(initials('  ')).toBe('?')
  })
})
