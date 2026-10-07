import { fireEvent, renderRouter, screen, waitFor } from 'expo-router/testing-library';
import { http, HttpResponse } from 'msw';

import { demoStore, issuedToken, profile, secondStore } from '../fixtures';
import { apiUrl, server } from '../server';
import { STORED_TOKEN, storeSession, storedOrganizationId, storedToken } from '../session';

async function openApp() {
  return renderRouter('./app', { initialUrl: '/' });
}

// renderRouter switches to fake timers and never switches back.
afterEach(() => jest.useRealTimers());

async function submitLogin(email = 'demo@example.com', password = 'secret-password') {
  await fireEvent.changeText(await screen.findByLabelText('E-mail'), email);
  await fireEvent.changeText(screen.getByLabelText('Senha'), password);
  await fireEvent.press(screen.getByRole('button', { name: 'Entrar' }));
}

describe('authentication flow', () => {
  it('opens the login screen when no token is stored', async () => {
    await openApp();

    expect(await screen.findByRole('button', { name: 'Entrar' })).toBeOnTheScreen();
  });

  it('logs in, stores the PAT in SecureStore and opens the app', async () => {
    let body: Record<string, unknown> | undefined;

    server.use(
      http.post(apiUrl('/auth/tokens'), async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(issuedToken(), { status: 201 });
      }),
    );

    await openApp();
    await submitLogin();

    expect(await screen.findByText('Olá, Demo')).toBeOnTheScreen();
    expect(body).toMatchObject({ email: 'demo@example.com', password: 'secret-password' });
    expect(body?.device_name).toMatch(/ · [0-9a-f]{4}$/);
    expect(await storedToken()).toBe('12|pbm_newtoken');
    expect(await storedOrganizationId()).toBe(demoStore.id);
  });

  it('shows the generic message for invalid credentials and stores nothing', async () => {
    server.use(
      http.post(apiUrl('/auth/tokens'), () =>
        HttpResponse.json(
          { message: 'The provided credentials are incorrect.', errors: { email: ['The provided credentials are incorrect.'] } },
          { status: 422 },
        ),
      ),
    );

    await openApp();
    await submitLogin('demo@example.com', 'wrong');

    expect(await screen.findByText('E-mail ou senha incorretos.')).toBeOnTheScreen();
    expect(await storedToken()).toBeNull();
  });

  it('shows the throttle wait time on 429', async () => {
    server.use(
      http.post(apiUrl('/auth/tokens'), () =>
        HttpResponse.json({ message: 'Too Many Attempts.' }, { status: 429, headers: { 'Retry-After': '30' } }),
      ),
    );

    await openApp();
    await submitLogin();

    expect(await screen.findByText('Muitas tentativas. Tente novamente em 30 segundos.')).toBeOnTheScreen();
  });

  it('does not call the API with empty fields', async () => {
    await openApp();
    await fireEvent.press(await screen.findByRole('button', { name: 'Entrar' }));

    expect(await screen.findByText('Informe e-mail e senha.')).toBeOnTheScreen();
  });

  it('restores the stored session when the app reopens', async () => {
    let authorization: string | null = null;
    let organizationHeader: string | null = null;

    await storeSession();
    server.use(
      http.get(apiUrl('/auth/me'), ({ request }) => {
        authorization = request.headers.get('authorization');
        organizationHeader = request.headers.get('x-organization-id');
        return HttpResponse.json(profile());
      }),
    );

    await openApp();

    expect(await screen.findByText('Olá, Demo')).toBeOnTheScreen();
    expect(authorization).toBe(`Bearer ${STORED_TOKEN}`);
    expect(organizationHeader).toBe(demoStore.id);
  });

  it('sends an expired or revoked token back to login and clears SecureStore', async () => {
    await storeSession();
    server.use(http.get(apiUrl('/auth/me'), () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })));

    await openApp();

    expect(await screen.findByText('Sua sessão expirou. Entre novamente.')).toBeOnTheScreen();
    expect(await storedToken()).toBeNull();
    expect(await storedOrganizationId()).toBeNull();
  });

  it('keeps the token and offers a retry when the API is unreachable on reopen', async () => {
    await storeSession();
    server.use(http.get(apiUrl('/auth/me'), () => HttpResponse.error()));

    await openApp();

    expect(await screen.findByText('Não foi possível validar sua sessão')).toBeOnTheScreen();
    expect(await storedToken()).toBe(STORED_TOKEN);

    server.use(http.get(apiUrl('/auth/me'), () => HttpResponse.json(profile())));
    await fireEvent.press(screen.getByRole('button', { name: 'Tentar novamente' }));

    expect(await screen.findByText('Olá, Demo')).toBeOnTheScreen();
  });

  it('logs out by revoking the current token', async () => {
    let revokedWith: string | null = null;

    await storeSession();
    server.use(
      http.get(apiUrl('/auth/me'), () => HttpResponse.json(profile())),
      http.delete(apiUrl('/auth/tokens/current'), ({ request }) => {
        revokedWith = request.headers.get('authorization');
        return new HttpResponse(null, { status: 204 });
      }),
    );

    await openApp();
    await screen.findByText('Olá, Demo');
    await fireEvent.press(screen.getByText('Conta'));
    await fireEvent.press(await screen.findByRole('button', { name: 'Sair da conta' }));

    expect(await screen.findByRole('button', { name: 'Entrar' })).toBeOnTheScreen();
    expect(revokedWith).toBe(`Bearer ${STORED_TOKEN}`);
    expect(await storedToken()).toBeNull();
  });

  it('asks which organization to use when the user has several', async () => {
    server.use(
      http.post(apiUrl('/auth/tokens'), () => HttpResponse.json(issuedToken([secondStore, demoStore]), { status: 201 })),
    );

    await openApp();
    await submitLogin();

    expect(await screen.findByText('Escolha a organização')).toBeOnTheScreen();
    await fireEvent.press(screen.getByRole('radio', { name: demoStore.name }));

    expect(await screen.findByText('Olá, Demo')).toBeOnTheScreen();
    expect(await storedOrganizationId()).toBe(demoStore.id);
  });

  it('restores the previously chosen organization without asking again', async () => {
    await storeSession(STORED_TOKEN, secondStore.id);
    server.use(http.get(apiUrl('/auth/me'), () => HttpResponse.json(profile([demoStore, secondStore]))));

    await openApp();

    expect(await screen.findByText(secondStore.name)).toBeOnTheScreen();
  });
});
