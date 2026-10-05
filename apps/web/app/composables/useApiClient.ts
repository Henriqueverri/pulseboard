import type { ApiErrorBody } from '~/types/api'
import { ApiError, parseRetryAfter } from '~/utils/api-error'

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

type QueryValue = string | number | boolean | null | undefined

export interface ApiRequestOptions {
  method?: HttpMethod
  body?: Record<string, unknown> | null
  query?: Record<string, QueryValue>
  /** `undefined` uses the current organization; `null` sends no `X-Organization-Id`. */
  organizationId?: string | null
  /** `false` for auth endpoints: a 401 there is an expected answer, not an expired session. */
  auth?: boolean
}

const XSRF_COOKIE = /(?:^|; )XSRF-TOKEN=([^;]*)/

function readXsrfToken(): string | null {
  if (!import.meta.client) {
    return null
  }

  const match = document.cookie.match(XSRF_COOKIE)

  return match?.[1] ? decodeURIComponent(match[1]) : null
}

function cleanQuery(query: Record<string, QueryValue> | undefined): Record<string, string | number | boolean> | undefined {
  if (!query) {
    return undefined
  }

  const entries = Object.entries(query).filter(
    (entry): entry is [string, string | number | boolean] => entry[1] !== undefined && entry[1] !== null && entry[1] !== '',
  )

  return entries.length > 0 ? Object.fromEntries(entries) : undefined
}

function toApiError(error: unknown): ApiError {
  if (error instanceof ApiError) {
    return error
  }

  const fetchError = error as { status?: number, statusCode?: number, data?: unknown, response?: { headers?: Headers } }
  const status = fetchError.statusCode ?? fetchError.status

  if (!status || !fetchError.response) {
    return new ApiError(0, null, 'Network error')
  }

  const body = fetchError.data && typeof fetchError.data === 'object' ? fetchError.data as ApiErrorBody : null
  const headers = fetchError.response.headers

  return new ApiError(status, body, undefined, {
    retryAfter: parseRetryAfter(headers?.get('Retry-After')),
    requestId: headers?.get('X-Request-Id') ?? null,
  })
}

/**
 * Single HTTP entry point for the Laravel API: Sanctum SPA cookies (`credentials: include`),
 * CSRF (`X-XSRF-TOKEN`), tenant context (`X-Organization-Id`) and normalized `ApiError`s.
 */
export function useApiClient() {
  const config = useRuntimeConfig()
  const authStore = useAuthStore()
  const nuxtApp = useNuxtApp()

  async function ensureCsrfCookie(): Promise<void> {
    await $fetch(`${config.public.apiOrigin}/sanctum/csrf-cookie`, {
      method: 'GET',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
  }

  function buildHeaders(options: ApiRequestOptions): Record<string, string> {
    const headers: Record<string, string> = {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    }

    const xsrf = readXsrfToken()
    if (xsrf) {
      headers['X-XSRF-TOKEN'] = xsrf
    }

    const organizationId = options.organizationId === undefined
      ? authStore.organization?.id
      : options.organizationId

    if (organizationId) {
      headers['X-Organization-Id'] = organizationId
    }

    return headers
  }

  async function send<T>(path: string, options: ApiRequestOptions): Promise<T> {
    return await $fetch<T>(`${config.public.apiUrl}${path}`, {
      method: options.method ?? 'GET',
      body: options.body ?? undefined,
      query: cleanQuery(options.query),
      credentials: 'include',
      headers: buildHeaders(options),
    })
  }

  async function handleFailure(error: ApiError, options: ApiRequestOptions): Promise<void> {
    if (error.isUnauthorized && options.auth !== false) {
      authStore.clearSession()
      await nuxtApp.runWithContext(() => {
        const route = useRoute()

        return navigateTo({ path: '/login', query: route.path === '/login' ? undefined : { redirect: route.fullPath } })
      })

      return
    }

    if (error.isOrganizationContext && options.organizationId !== null) {
      await authStore.recoverOrganization()
    }
  }

  async function apiFetch<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
    const method = options.method ?? 'GET'
    const isMutation = !['GET', 'HEAD', 'OPTIONS'].includes(method)

    if (isMutation && !readXsrfToken()) {
      await ensureCsrfCookie()
    }

    try {
      return await send<T>(path, options)
    }
    catch (rawError: unknown) {
      let error = toApiError(rawError)

      // 419: CSRF token mismatch (expired session token). Refresh it and retry once.
      if (error.status === 419 && isMutation) {
        try {
          await ensureCsrfCookie()

          return await send<T>(path, options)
        }
        catch (retryError: unknown) {
          error = toApiError(retryError)
        }
      }

      await handleFailure(error, options)

      throw error
    }
  }

  return {
    apiFetch,
    ensureCsrfCookie,
  }
}
