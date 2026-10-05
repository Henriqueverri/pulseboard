import type { ApiErrorBody } from '~/types/api'

export const ORGANIZATION_ACCESS_DENIED = 'You do not have access to this organization.'

export interface ApiErrorMeta {
  /** Seconds from the `Retry-After` header (429). */
  retryAfter?: number | null
  /** `X-Request-Id` the API assigned to the failed request. */
  requestId?: string | null
}

/**
 * Normalized failure of an API request. `status` is 0 for network errors.
 */
export class ApiError extends Error {
  status: number
  body: ApiErrorBody | null
  retryAfter: number | null
  requestId: string | null

  constructor(status: number, body: ApiErrorBody | null, message?: string, meta: ApiErrorMeta = {}) {
    super(message || body?.message || `Request failed with status ${status}`)
    this.name = 'ApiError'
    this.status = status
    this.body = body
    this.retryAfter = meta.retryAfter ?? null
    this.requestId = meta.requestId ?? null
  }

  get code(): string | null {
    return this.body?.code ?? null
  }

  get fieldErrors(): Record<string, string[]> {
    return this.body?.errors ?? {}
  }

  get isRateLimited(): boolean {
    return this.status === 429
  }

  get isValidation(): boolean {
    return this.status === 422
  }

  get isUnauthorized(): boolean {
    return this.status === 401
  }

  get isForbidden(): boolean {
    return this.status === 403
  }

  get isNotFound(): boolean {
    return this.status === 404
  }

  get isNetwork(): boolean {
    return this.status === 0
  }

  /** Missing/invalid `X-Organization-Id` (400) or no membership in it (403). */
  get isOrganizationContext(): boolean {
    if (this.status === 400) {
      return (this.body?.message ?? '').includes('X-Organization-Id')
    }

    return this.status === 403 && this.body?.message === ORGANIZATION_ACCESS_DENIED
  }
}

const KNOWN_MESSAGES: Array<[RegExp, string]> = [
  [/credentials are incorrect/i, 'E-mail ou senha incorretos.'],
  [/SKU is already used/i, 'Este SKU já está em uso por outro produto desta organização (incluindo produtos removidos).'],
  [/email is already used by another customer/i, 'Este e-mail já está em uso por outro cliente desta organização (incluindo clientes removidos).'],
  [/email has already been taken/i, 'Este e-mail já está cadastrado.'],
  [/requires the owner role/i, 'Somente owners podem executar esta ação.'],
  [/^This action is unauthorized\.?$/i, 'Você não tem permissão para esta operação.'],
  [/already has (\d+) active API keys/i, 'Esta organização já tem $1 chaves ativas. Revogue uma antes de criar outra.'],
  [/external id may only contain/i, 'Use apenas letras, números, ponto, sublinhado, dois-pontos e hífen.'],
  [/external id is already used by another product/i, 'Este ID externo já está em uso por outro produto desta organização (incluindo produtos removidos).'],
  [/external id is already used by another customer/i, 'Este ID externo já está em uso por outro cliente desta organização (incluindo clientes removidos).'],
  [/too many (login )?attempts/i, 'Muitas tentativas. Aguarde um minuto e tente novamente.'],
  [/do not have access to this organization/i, 'Você não tem acesso a esta organização.'],
  [/password field confirmation does not match/i, 'A confirmação de senha não confere.'],
  [/password field must be at least/i, 'A senha deve ter pelo menos 8 caracteres.'],
  [/period may not be longer than/i, 'O período pode ter no máximo 366 dias.'],
  [/to date must be on or after/i, 'A data final deve ser igual ou posterior à inicial.'],
  [/^The .+ field is required\.?$/i, 'Campo obrigatório.'],
  [/must be a valid email address/i, 'Informe um e-mail válido.'],
  [/^The .+ field must not be greater than (\d+) characters\.?$/i, 'Use no máximo $1 caracteres.'],
]

/** Translates the API messages the UI knows about; other messages are shown as received. */
export function translateApiMessage(message: string): string {
  for (const [pattern, translation] of KNOWN_MESSAGES) {
    const match = message.match(pattern)
    if (match) {
      return translation.replace(/\$(\d)/g, (_, group: string) => match[Number(group)] ?? '')
    }
  }

  return message
}

/** `Retry-After` as seconds: delta-seconds or an HTTP date; `null` when absent or unparseable. */
export function parseRetryAfter(value: string | null | undefined, now = Date.now()): number | null {
  if (!value) {
    return null
  }

  const trimmed = value.trim()
  if (/^\d+$/.test(trimmed)) {
    return Number(trimmed)
  }

  const date = Date.parse(trimmed)

  return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - now) / 1000))
}

export function rateLimitMessage(retryAfter: number | null): string {
  if (retryAfter === null) {
    return 'Muitas tentativas. Aguarde um minuto e tente novamente.'
  }

  return retryAfter <= 1
    ? 'Muitas tentativas. Tente novamente em 1 segundo.'
    : `Muitas tentativas. Tente novamente em ${retryAfter} segundos.`
}

/** The `ApiError` behind an error, including the `NuxtError` wrapper `useAsyncData` exposes. */
export function asApiError(error: unknown): ApiError | null {
  if (error instanceof ApiError) {
    return error
  }

  const cause = (error as { cause?: unknown } | null)?.cause

  return cause instanceof ApiError ? cause : null
}

/** User-facing message for any error thrown by a request. */
export function errorMessage(rawError: unknown, fallback = 'Algo deu errado. Tente novamente.'): string {
  const error = asApiError(rawError)

  if (error) {
    if (error.isNetwork) {
      return 'Não foi possível conectar à API. Verifique sua conexão.'
    }
    if (error.isRateLimited) {
      return rateLimitMessage(error.retryAfter)
    }
    if (error.status >= 500) {
      return 'O servidor encontrou um erro. Tente novamente em instantes.'
    }
    if (error.isNotFound) {
      return 'Registro não encontrado.'
    }
    if (error.isForbidden && !error.isOrganizationContext) {
      const message = error.body?.message ? translateApiMessage(error.body.message) : null

      return message && message !== error.body?.message ? message : 'Você não tem permissão para esta operação.'
    }

    return error.body?.message ? translateApiMessage(error.body.message) : fallback
  }

  return fallback
}
