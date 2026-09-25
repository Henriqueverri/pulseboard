import type { ApiErrorBody } from '~/types/auth'
import { ApiError } from '~/types/auth'

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface ApiRequestOptions {
  method?: HttpMethod
  body?: Record<string, unknown> | null
  organizationId?: string | null
  auth?: boolean
}

function readXsrfToken(): string | null {
  if (!import.meta.client) {
    return null
  }

  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)
  if (!match?.[1]) {
    return null
  }

  return decodeURIComponent(match[1])
}

export function useApiClient() {
  const config = useRuntimeConfig()
  const authStore = useAuthStore()

  async function ensureCsrfCookie(): Promise<void> {
    await $fetch(`${config.public.apiOrigin}/sanctum/csrf-cookie`, {
      method: 'GET',
      credentials: 'include',
    })
  }

  async function apiFetch<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
    const method = options.method ?? 'GET'
    const isMutation = !['GET', 'HEAD', 'OPTIONS'].includes(method)

    if (isMutation) {
      await ensureCsrfCookie()
    }

    const headers: Record<string, string> = {
      Accept: 'application/json',
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

    try {
      return await $fetch<T>(`${config.public.apiUrl}${path}`, {
        method,
        body: options.body ?? undefined,
        credentials: 'include',
        headers,
      })
    }
    catch (error: unknown) {
      const fetchError = error as {
        status?: number
        statusCode?: number
        data?: ApiErrorBody
      }

      const status = fetchError.statusCode ?? fetchError.status ?? 500
      const body = fetchError.data ?? null

      if (status === 401 && options.auth !== false) {
        authStore.clearSession()
      }

      throw new ApiError(status, body)
    }
  }

  return {
    apiFetch,
    ensureCsrfCookie,
  }
}
