// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { resolveAuthRedirect, safeRedirect } from '../../app/utils/auth-redirect'

describe('safeRedirect', () => {
  it('accepts in-app paths', () => {
    expect(safeRedirect('/products?page=2')).toBe('/products?page=2')
    expect(safeRedirect(['/customers/1'])).toBe('/customers/1')
  })

  it.each([undefined, null, '', 'https://evil.test', '//evil.test', '/\\evil.test', 'products', 42])(
    'rejects %o',
    (value) => {
      expect(safeRedirect(value)).toBeNull()
    },
  )
})

describe('resolveAuthRedirect', () => {
  const protectedRoute = (fullPath: string) => ({ auth: undefined, fullPath, redirect: undefined })

  it('sends guests on protected pages to login keeping the destination', () => {
    expect(resolveAuthRedirect(protectedRoute('/products?q=mouse'), false))
      .toEqual({ path: '/login', query: { redirect: '/products?q=mouse' } })
  })

  it('does not add a redirect for the home page', () => {
    expect(resolveAuthRedirect(protectedRoute('/dashboard'), false)).toBe('/login')
    expect(resolveAuthRedirect(protectedRoute('/'), false)).toBe('/login')
  })

  it('lets authenticated users through protected pages', () => {
    expect(resolveAuthRedirect(protectedRoute('/products'), true)).toBeNull()
  })

  it('sends authenticated users away from guest pages, honoring a safe redirect', () => {
    expect(resolveAuthRedirect({ auth: 'guest', fullPath: '/login', redirect: undefined }, true)).toBe('/dashboard')
    expect(resolveAuthRedirect({ auth: 'guest', fullPath: '/login', redirect: '/customers' }, true)).toBe('/customers')
    expect(resolveAuthRedirect({ auth: 'guest', fullPath: '/login', redirect: '//evil.test' }, true)).toBe('/dashboard')
    expect(resolveAuthRedirect({ auth: 'guest', fullPath: '/login', redirect: undefined }, false)).toBeNull()
  })

  it('ignores public pages', () => {
    expect(resolveAuthRedirect({ auth: false, fullPath: '/dev/ui', redirect: undefined }, false)).toBeNull()
    expect(resolveAuthRedirect({ auth: false, fullPath: '/dev/ui', redirect: undefined }, true)).toBeNull()
  })
})
