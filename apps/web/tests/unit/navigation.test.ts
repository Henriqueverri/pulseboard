// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { buildBreadcrumbs, isNavItemActive } from '../../app/utils/navigation'

describe('isNavItemActive', () => {
  it('matches the section and its nested pages only', () => {
    const item = { match: '/products' }

    expect(isNavItemActive(item, '/products')).toBe(true)
    expect(isNavItemActive(item, '/products/0199')).toBe(true)
    expect(isNavItemActive(item, '/products-archive')).toBe(false)
    expect(isNavItemActive({ match: '/analytics' }, '/analytics/revenue')).toBe(true)
  })
})

describe('buildBreadcrumbs', () => {
  it('labels static sections and leaves the current page without link', () => {
    expect(buildBreadcrumbs('/products', null)).toEqual([{ label: 'Produtos' }])
    expect(buildBreadcrumbs('/dashboard', null)).toEqual([{ label: 'Dashboard' }])
  })

  it('uses the loaded record name for detail pages', () => {
    expect(buildBreadcrumbs('/customers/0199-abc', 'Maria Souza')).toEqual([
      { label: 'Clientes', to: '/customers' },
      { label: 'Maria Souza' },
    ])
    expect(buildBreadcrumbs('/customers/0199-abc', null).at(-1)).toEqual({ label: 'Detalhe' })
  })

  it('points the analytics crumb at its first tab', () => {
    expect(buildBreadcrumbs('/analytics/products', null)).toEqual([
      { label: 'Analytics', to: '/analytics/revenue' },
      { label: 'Produtos' },
    ])
  })
})
