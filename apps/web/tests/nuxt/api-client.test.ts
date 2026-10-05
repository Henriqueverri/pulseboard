import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '~/utils/api-error'

const { fetchMock } = vi.hoisted(() => ({ fetchMock: vi.fn() }))

mockNuxtImport('$fetch', () => fetchMock)

const ORG = {
  id: '9d1b0000-0000-4000-8000-000000000001',
  name: 'Demo',
  slug: 'demo',
  currency: 'BRL',
  timezone: 'America/Sao_Paulo',
  role: 'owner' as const,
}

function fetchError(status: number, data: unknown) {
  return Object.assign(new Error(`HTTP ${status}`), { status, statusCode: status, data, response: {} })
}

function clearCookies() {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
}

describe('useApiClient', () => {
  beforeEach(() => {
    clearCookies()
    fetchMock.mockReset()

    const store = useAuthStore()
    store.setSession({ id: 'u1', name: 'Test', email: 'test@example.com' }, ORG, [ORG])
  })

  it('sends cookies, JSON headers and the organization context', async () => {
    fetchMock.mockResolvedValueOnce({ data: [] })
    const { apiFetch } = useApiClient()

    await apiFetch('/products', { query: { q: 'mouse', status: undefined, page: 2, empty: '' } })

    const [url, options] = fetchMock.mock.calls[0]!
    expect(url).toBe('http://localhost:8000/api/v1/products')
    expect(options.credentials).toBe('include')
    expect(options.query).toEqual({ q: 'mouse', page: 2 })
    expect(options.headers).toMatchObject({
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-Organization-Id': ORG.id,
    })
  })

  it('omits the organization header when asked to', async () => {
    fetchMock.mockResolvedValueOnce({})
    const { apiFetch } = useApiClient()

    await apiFetch('/auth/me', { organizationId: null })

    expect(fetchMock.mock.calls[0]![1].headers['X-Organization-Id']).toBeUndefined()
  })

  it('fetches the CSRF cookie before a mutation only when it is missing', async () => {
    fetchMock.mockImplementation(async (url: string) => {
      if (url.endsWith('/sanctum/csrf-cookie')) {
        document.cookie = 'XSRF-TOKEN=token%3D1; path=/'
      }

      return {}
    })
    const { apiFetch } = useApiClient()

    await apiFetch('/products', { method: 'POST', body: { name: 'A' } })
    await apiFetch('/products', { method: 'POST', body: { name: 'B' } })

    const urls = fetchMock.mock.calls.map(call => call[0])
    expect(urls.filter(url => url.endsWith('/sanctum/csrf-cookie'))).toHaveLength(1)
    expect(fetchMock.mock.calls[1]![1].headers['X-XSRF-TOKEN']).toBe('token=1')
  })

  it('refreshes the CSRF token and retries once on 419', async () => {
    document.cookie = 'XSRF-TOKEN=stale; path=/'
    fetchMock
      .mockRejectedValueOnce(fetchError(419, { message: 'CSRF token mismatch.' }))
      .mockResolvedValueOnce({})
      .mockResolvedValueOnce({ data: { id: 'p1' } })
    const { apiFetch } = useApiClient()

    await expect(apiFetch('/products', { method: 'POST', body: {} })).resolves.toEqual({ data: { id: 'p1' } })
    expect(fetchMock.mock.calls[1]![0]).toMatch(/sanctum\/csrf-cookie$/)
  })

  it('normalizes validation errors', async () => {
    document.cookie = 'XSRF-TOKEN=t; path=/'
    fetchMock.mockRejectedValueOnce(fetchError(422, { message: 'Invalid', errors: { sku: ['taken'] } }))
    const { apiFetch } = useApiClient()

    const error = await apiFetch('/products', { method: 'POST', body: {} }).then(() => null, (e: ApiError) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error?.fieldErrors).toEqual({ sku: ['taken'] })
  })

  it('clears the session on 401', async () => {
    fetchMock.mockRejectedValueOnce(fetchError(401, { message: 'Unauthenticated.' }))
    const { apiFetch } = useApiClient()

    await expect(apiFetch('/products')).rejects.toMatchObject({ status: 401 })
    expect(useAuthStore().isAuthenticated).toBe(false)
  })

  it('does not clear the session on 401 from auth endpoints', async () => {
    fetchMock.mockRejectedValueOnce(fetchError(401, { message: 'Unauthenticated.' }))
    const { apiFetch } = useApiClient()

    await expect(apiFetch('/auth/me', { auth: false, organizationId: null })).rejects.toMatchObject({ status: 401 })
    expect(useAuthStore().isAuthenticated).toBe(true)
  })

  it('recovers the organization context on 403 without membership', async () => {
    const other = { ...ORG, id: '9d1b0000-0000-4000-8000-000000000002', name: 'Other' }
    fetchMock
      .mockRejectedValueOnce(fetchError(403, { message: 'You do not have access to this organization.' }))
      .mockResolvedValueOnce({ user: { id: 'u1', name: 'Test', email: 'test@example.com' }, organizations: [other], current_organization: other })
    const { apiFetch } = useApiClient()

    await expect(apiFetch('/dashboard')).rejects.toMatchObject({ status: 403 })
    expect(useAuthStore().organization?.id).toBe(other.id)
  })

  it('reports network failures with status 0', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    const { apiFetch } = useApiClient()

    await expect(apiFetch('/products')).rejects.toMatchObject({ status: 0 })
  })

  it('keeps the error code, Retry-After and request id of a failed response', async () => {
    const headers = new Headers({ 'Retry-After': '17', 'X-Request-Id': 'req-abcdef12' })
    fetchMock.mockRejectedValueOnce(Object.assign(fetchError(429, { message: 'Too many requests.', code: 'rate_limited' }), { response: { headers } }))
    const { apiFetch } = useApiClient()

    const error = await apiFetch('/products').catch((rawError: unknown) => rawError)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 429, code: 'rate_limited', retryAfter: 17, requestId: 'req-abcdef12' })
  })
})
