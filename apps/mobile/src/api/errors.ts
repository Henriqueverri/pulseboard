export interface ApiErrorBody {
  message?: string;
  code?: string;
  errors?: Record<string, string[]>;
}

interface ApiErrorInit {
  /** HTTP status; `0` when no response arrived (network failure or timeout). */
  status: number;
  body?: ApiErrorBody | null;
  requestId?: string | null;
  retryAfter?: number | null;
  timedOut?: boolean;
}

export const ORGANIZATION_ACCESS_DENIED = 'You do not have access to this organization.';

/** Every failed request becomes an `ApiError`, so screens never inspect raw responses. */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string | null;
  readonly errors: Record<string, string[]>;
  readonly requestId: string | null;
  readonly retryAfter: number | null;
  readonly timedOut: boolean;

  constructor({ status, body = null, requestId = null, retryAfter = null, timedOut = false }: ApiErrorInit) {
    super(body?.message || (status === 0 ? 'Network request failed' : `Request failed with status ${status}`));
    this.name = 'ApiError';
    this.status = status;
    this.code = body?.code ?? null;
    this.errors = body?.errors ?? {};
    this.requestId = requestId;
    this.retryAfter = retryAfter;
    this.timedOut = timedOut;
  }

  get isNetwork(): boolean {
    return this.status === 0;
  }

  get isServerError(): boolean {
    return this.status >= 500;
  }

  get isUnauthorized(): boolean {
    return this.status === 401;
  }

  get isNotFound(): boolean {
    return this.status === 404;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }

  get isRateLimited(): boolean {
    return this.status === 429;
  }

  /** Missing/invalid `X-Organization-Id` (400) or no membership in that organization (403). */
  get isOrganizationContext(): boolean {
    if (this.status === 400) {
      return this.message.includes('X-Organization-Id');
    }

    return this.status === 403 && this.message === ORGANIZATION_ACCESS_DENIED;
  }

  /** First validation message of a field, if any. */
  fieldError(field: string): string | null {
    return this.errors[field]?.[0] ?? null;
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError;
}

/** `Retry-After` in seconds: delta-seconds or an HTTP date; `null` when absent or unparseable. */
export function parseRetryAfter(value: string | null, now = Date.now()): number | null {
  if (!value) {
    return null;
  }

  const trimmed = value.trim();

  if (/^\d+$/.test(trimmed)) {
    return Number(trimmed);
  }

  const date = Date.parse(trimmed);

  return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - now) / 1000));
}

const KNOWN_MESSAGES: [RegExp, string][] = [
  [/credentials are incorrect/i, 'E-mail ou senha incorretos.'],
  [/too many (login )?attempts/i, 'Muitas tentativas. Aguarde um minuto e tente novamente.'],
  [/do not have access to this organization/i, 'Você não tem acesso a esta organização.'],
  [/^The .+ field is required\.?$/i, 'Campo obrigatório.'],
  [/must be a valid email address/i, 'Informe um e-mail válido.'],
];

/** Translates the API messages the app knows about; others are shown as received. */
export function translateApiMessage(message: string): string {
  const known = KNOWN_MESSAGES.find(([pattern]) => pattern.test(message));

  return known ? known[1] : message;
}

/** User-facing (pt-BR) message for any error thrown by a request. */
export function errorMessage(error: unknown, fallback = 'Algo deu errado. Tente novamente.'): string {
  if (!isApiError(error)) {
    return fallback;
  }

  if (error.timedOut) {
    return 'O servidor demorou para responder. Ele pode estar iniciando: tente novamente em instantes.';
  }

  if (error.isNetwork) {
    return 'Não foi possível conectar à API. Verifique sua conexão.';
  }

  if (error.isRateLimited) {
    return error.retryAfter
      ? `Muitas tentativas. Tente novamente em ${error.retryAfter} segundos.`
      : 'Muitas tentativas. Aguarde um minuto e tente novamente.';
  }

  if (error.isServerError) {
    return 'O servidor encontrou um erro. Tente novamente em instantes.';
  }

  if (error.isNotFound) {
    return 'Registro não encontrado.';
  }

  if (error.isUnauthorized) {
    return 'Sua sessão expirou. Entre novamente.';
  }

  if (error.isOrganizationContext) {
    return 'Você não tem acesso a esta organização.';
  }

  if (error.status === 403) {
    return 'Você não tem permissão para esta operação.';
  }

  return translateApiMessage(error.message) || fallback;
}
