import { API_URL, REQUEST_TIMEOUT_MS } from '@/config';

import { ApiError, parseRetryAfter, type ApiErrorBody } from './errors';

type HttpMethod = 'GET' | 'POST' | 'DELETE';
type QueryValue = string | number | boolean | null | undefined;

export interface RequestOptions {
  method?: HttpMethod;
  query?: Record<string, QueryValue>;
  body?: unknown;
  signal?: AbortSignal;
  timeoutMs?: number;
  /**
   * `false` for credential endpoints (`POST /auth/tokens`): no Bearer is sent and a 401/422
   * there is an answer, not an expired session.
   */
  authenticated?: boolean;
}

interface SessionBridge {
  token: string | null;
  organizationId: string | null;
  /** Called once per 401 on an authenticated request (expired or revoked token). */
  onUnauthorized: (() => void) | null;
}

const session: SessionBridge = {
  token: null,
  organizationId: null,
  onUnauthorized: null,
};

/** The session layer pushes its credentials here; the client never reads storage itself. */
export function configureApiSession(next: Partial<SessionBridge>): void {
  Object.assign(session, next);
}

export function buildUrl(path: string, query?: Record<string, QueryValue>): string {
  const params = Object.entries(query ?? {})
    .filter((entry): entry is [string, string | number | boolean] => entry[1] !== undefined && entry[1] !== null && entry[1] !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join('&');

  return `${API_URL}${path}${params ? `?${params}` : ''}`;
}

function parseBody(text: string): unknown {
  if (text === '') {
    return null;
  }

  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/**
 * Single HTTP entry point for the PulseBoard API: Bearer PAT, tenant context
 * (`X-Organization-Id`), timeout and normalized `ApiError`s. Headers are never logged.
 */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', query, body, signal, timeoutMs = REQUEST_TIMEOUT_MS, authenticated = true } = options;

  const headers: Record<string, string> = { Accept: 'application/json' };

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  if (authenticated && session.token) {
    headers.Authorization = `Bearer ${session.token}`;
  }

  if (authenticated && session.organizationId) {
    headers['X-Organization-Id'] = session.organizationId;
  }

  // AbortSignal.any is not available everywhere (Hermes), so the caller's signal is chained by hand.
  const controller = new AbortController();
  let timedOut = false;
  const timer = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, timeoutMs);
  const abortFromCaller = () => controller.abort();
  signal?.addEventListener('abort', abortFromCaller);

  let response: Response;

  try {
    response = await fetch(buildUrl(path, query), {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    });
  } catch (error) {
    if (signal?.aborted) {
      throw error;
    }

    throw new ApiError({ status: 0, timedOut });
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', abortFromCaller);
  }

  const payload = parseBody(await response.text());

  if (!response.ok) {
    const error = new ApiError({
      status: response.status,
      body: payload && typeof payload === 'object' ? (payload as ApiErrorBody) : null,
      requestId: response.headers.get('X-Request-Id'),
      retryAfter: parseRetryAfter(response.headers.get('Retry-After')),
    });

    if (error.isUnauthorized && authenticated && session.token) {
      session.onUnauthorized?.();
    }

    throw error;
  }

  return payload as T;
}
