import type { ApiErrorBody } from '~/types/api'

export const ORGANIZATION_ACCESS_DENIED = 'You do not have access to this organization.'

/**
 * Normalized failure of an API request. `status` is 0 for network errors.
 */
export class ApiError extends Error {
  status: number
  body: ApiErrorBody | null

  constructor(status: number, body: ApiErrorBody | null, message?: string) {
    super(message || body?.message || `Request failed with status ${status}`)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }

  get fieldErrors(): Record<string, string[]> {
    return this.body?.errors ?? {}
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
    if (error.status === 429) {
      return 'Muitas tentativas. Aguarde um minuto e tente novamente.'
    }
    if (error.status >= 500) {
      return 'O servidor encontrou um erro. Tente novamente em instantes.'
    }
    if (error.isNotFound) {
      return 'Registro não encontrado.'
    }

    return error.body?.message ? translateApiMessage(error.body.message) : fallback
  }

  return fallback
}
