import { http, HttpResponse } from 'msw';

import { apiUrl, server } from '@test/server';

import { apiRequest, buildUrl, configureApiSession } from '../client';
import { ApiError, errorMessage } from '../errors';

const ORG_ID = '9d1a0000-0000-4000-8000-000000000001';

async function captureError(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise;
  } catch (error) {
    if (error instanceof ApiError) {
      return error;
    }
    throw error;
  }
  throw new Error('Expected the request to fail');
}

afterEach(() => {
  configureApiSession({ token: null, organizationId: null, onUnauthorized: null });
});

describe('apiRequest', () => {
  it('sends Accept, the Bearer token and X-Organization-Id', async () => {
    configureApiSession({ token: '12|pbm_secret', organizationId: ORG_ID });
    let headers: Headers | undefined;

    server.use(
      http.get(apiUrl('/dashboard'), ({ request }) => {
        headers = request.headers;
        return HttpResponse.json({ data: {} });
      }),
    );

    await apiRequest('/dashboard');

    expect(headers?.get('accept')).toBe('application/json');
    expect(headers?.get('authorization')).toBe('Bearer 12|pbm_secret');
    expect(headers?.get('x-organization-id')).toBe(ORG_ID);
  });

  it('sends no credentials on unauthenticated endpoints', async () => {
    configureApiSession({ token: '12|pbm_secret', organizationId: ORG_ID });
    let headers: Headers | undefined;

    server.use(
      http.get(apiUrl('/health'), ({ request }) => {
        headers = request.headers;
        return HttpResponse.json({ status: 'ok', database: 'ok' });
      }),
    );

    await apiRequest('/health', { authenticated: false });

    expect(headers?.get('authorization')).toBeNull();
    expect(headers?.get('x-organization-id')).toBeNull();
  });

  it('serializes the JSON body and drops empty query values', async () => {
    let received: { url: string; body: unknown; contentType: string | null } | undefined;

    server.use(
      http.post(apiUrl('/auth/tokens'), async ({ request }) => {
        received = { url: request.url, body: await request.json(), contentType: request.headers.get('content-type') };
        return HttpResponse.json({}, { status: 201 });
      }),
    );

    await apiRequest('/auth/tokens', {
      method: 'POST',
      body: { email: 'a@b.c' },
      query: { a: 1, b: '', c: null, d: undefined },
      authenticated: false,
    });

    expect(received?.url).toBe(apiUrl('/auth/tokens?a=1'));
    expect(received?.body).toEqual({ email: 'a@b.c' });
    expect(received?.contentType).toBe('application/json');
  });

  it('resolves to null on 204 No Content', async () => {
    server.use(http.delete(apiUrl('/auth/tokens/current'), () => new HttpResponse(null, { status: 204 })));

    await expect(apiRequest('/auth/tokens/current', { method: 'DELETE' })).resolves.toBeNull();
  });

  it('normalizes 422 with field errors and the X-Request-Id', async () => {
    server.use(
      http.post(apiUrl('/auth/tokens'), () =>
        HttpResponse.json(
          { message: 'The provided credentials are incorrect.', errors: { email: ['The provided credentials are incorrect.'] } },
          { status: 422, headers: { 'X-Request-Id': 'req-422-abcdef' } },
        ),
      ),
    );

    const error = await captureError(apiRequest('/auth/tokens', { method: 'POST', body: {}, authenticated: false }));

    expect(error.status).toBe(422);
    expect(error.isValidation).toBe(true);
    expect(error.fieldError('email')).toBe('The provided credentials are incorrect.');
    expect(error.requestId).toBe('req-422-abcdef');
    expect(errorMessage(error)).toBe('E-mail ou senha incorretos.');
  });

  it('exposes Retry-After on 429', async () => {
    server.use(
      http.post(apiUrl('/auth/tokens'), () =>
        HttpResponse.json({ message: 'Too Many Attempts.' }, { status: 429, headers: { 'Retry-After': '42' } }),
      ),
    );

    const error = await captureError(apiRequest('/auth/tokens', { method: 'POST', body: {}, authenticated: false }));

    expect(error.isRateLimited).toBe(true);
    expect(error.retryAfter).toBe(42);
    expect(errorMessage(error)).toBe('Muitas tentativas. Tente novamente em 42 segundos.');
  });

  it('calls onUnauthorized on a 401 of an authenticated request', async () => {
    const onUnauthorized = jest.fn();
    configureApiSession({ token: '12|pbm_revoked', organizationId: ORG_ID, onUnauthorized });

    server.use(http.get(apiUrl('/auth/me'), () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })));

    const error = await captureError(apiRequest('/auth/me'));

    expect(error.isUnauthorized).toBe(true);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('does not treat a 401 of a credential endpoint as an expired session', async () => {
    const onUnauthorized = jest.fn();
    configureApiSession({ token: null, onUnauthorized });

    server.use(http.post(apiUrl('/auth/tokens'), () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })));

    await captureError(apiRequest('/auth/tokens', { method: 'POST', body: {}, authenticated: false }));

    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('flags organization context errors (400 without header, 403 without membership)', async () => {
    server.use(
      http.get(apiUrl('/dashboard'), () =>
        HttpResponse.json({ message: 'You do not have access to this organization.' }, { status: 403 }),
      ),
      http.get(apiUrl('/organization'), () =>
        HttpResponse.json({ message: 'The X-Organization-Id header is required.' }, { status: 400 }),
      ),
    );

    expect((await captureError(apiRequest('/dashboard'))).isOrganizationContext).toBe(true);
    expect((await captureError(apiRequest('/organization'))).isOrganizationContext).toBe(true);
  });

  it('normalizes 5xx without a JSON body', async () => {
    server.use(http.get(apiUrl('/health'), () => new HttpResponse('<html>Bad gateway</html>', { status: 502 })));

    const error = await captureError(apiRequest('/health', { authenticated: false }));

    expect(error.status).toBe(502);
    expect(error.isServerError).toBe(true);
    expect(errorMessage(error)).toBe('O servidor encontrou um erro. Tente novamente em instantes.');
  });

  it('turns a network failure into status 0', async () => {
    server.use(http.get(apiUrl('/health'), () => HttpResponse.error()));

    const error = await captureError(apiRequest('/health', { authenticated: false }));

    expect(error.isNetwork).toBe(true);
    expect(error.timedOut).toBe(false);
    expect(errorMessage(error)).toBe('Não foi possível conectar à API. Verifique sua conexão.');
  });
});

describe('buildUrl', () => {
  it('encodes query values', () => {
    expect(buildUrl('/transactions', { q: 'pos:1 2', page: 2 })).toBe(apiUrl('/transactions?q=pos%3A1%202&page=2'));
  });
});
