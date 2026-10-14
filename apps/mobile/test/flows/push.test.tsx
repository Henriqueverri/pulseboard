import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { router } from 'expo-router';
import { act, fireEvent, renderRouter, screen, waitFor } from 'expo-router/testing-library';
import { http, HttpResponse } from 'msw';

import { demoStore, profile, secondStore, transactionsPage } from '../fixtures';
import { apiUrl, server } from '../server';
import { STORED_TOKEN, storeSession } from '../session';

const TOKEN = 'ExponentPushToken[test-device]';
const GRANTED = { granted: true, canAskAgain: true, status: 'granted' } as Notifications.NotificationPermissionsStatus;
const BLOCKED = { granted: false, canAskAgain: false, status: 'denied' } as Notifications.NotificationPermissionsStatus;

interface DeviceRequest {
  method: string;
  body: unknown;
  authorization: string | null;
}

function serveDevices(organizations = [demoStore]) {
  const requests: DeviceRequest[] = [];
  const record = async (request: Request) => {
    requests.push({ method: request.method, body: await request.json(), authorization: request.headers.get('authorization') });

    return new HttpResponse(null, { status: 204 });
  };

  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json(profile(organizations))),
    http.post(apiUrl('/devices/push-token'), ({ request }) => record(request)),
    http.delete(apiUrl('/devices/push-token'), ({ request }) => record(request)),
  );

  return requests;
}

async function openApp() {
  await storeSession();
  await renderRouter('./app', { initialUrl: '/' });
  await screen.findByText('PulseBoard Demo Store');
}

function tap(data: Record<string, string>, identifier = 'notification-1') {
  (jest.requireMock('expo-notifications') as { __setLastResponse: (response: unknown) => void }).__setLastResponse({
    actionIdentifier: Notifications.DEFAULT_ACTION_IDENTIFIER,
    notification: { request: { identifier, content: { title: 'Meta do dia batida', body: '', data } } },
  });
}

afterEach(() => {
  jest.mocked(Notifications.getPermissionsAsync).mockImplementation(async () => GRANTED);
  jest.mocked(Notifications.requestPermissionsAsync).mockClear();
});

describe('push registration', () => {
  it('registers the Expo token with the API once the session is signed in', async () => {
    const requests = serveDevices();

    await openApp();

    await waitFor(() => expect(requests).toHaveLength(1));
    expect(requests[0]).toEqual({
      method: 'POST',
      body: { token: TOKEN, platform: 'ios' },
      authorization: `Bearer ${STORED_TOKEN}`,
    });
    expect(await SecureStore.getItemAsync('pulseboard.push_token')).toBe(TOKEN);
  });

  it('does not ask for permission again on a device where alerts were turned off', async () => {
    await SecureStore.setItemAsync('pulseboard.push_disabled', '1');
    const requests = serveDevices();

    await openApp();
    await act(() => router.push('/account'));

    expect(await screen.findByRole('switch', { name: 'Avisos no celular' })).toHaveProp('value', false);
    expect(requests).toHaveLength(0);
    expect(Notifications.requestPermissionsAsync).not.toHaveBeenCalled();
  });
});

describe('push setting on the account screen', () => {
  it('turns the alerts off (removing the device) and on again', async () => {
    const requests = serveDevices();

    await openApp();
    await waitFor(() => expect(requests).toHaveLength(1));
    await act(() => router.push('/account'));

    const toggle = await screen.findByRole('switch', { name: 'Avisos no celular' });
    await waitFor(() => expect(toggle).toHaveProp('value', true));

    await fireEvent(toggle, 'valueChange', false);

    await waitFor(() => expect(screen.getByRole('switch', { name: 'Avisos no celular' })).toHaveProp('value', false));
    expect(requests[1]).toEqual({ method: 'DELETE', body: { token: TOKEN }, authorization: `Bearer ${STORED_TOKEN}` });
    expect(await SecureStore.getItemAsync('pulseboard.push_disabled')).toBe('1');

    await fireEvent(screen.getByRole('switch', { name: 'Avisos no celular' }), 'valueChange', true);

    await waitFor(() => expect(screen.getByRole('switch', { name: 'Avisos no celular' })).toHaveProp('value', true));
    expect(requests[2]).toMatchObject({ method: 'POST', body: { token: TOKEN, platform: 'ios' } });
    expect(await SecureStore.getItemAsync('pulseboard.push_disabled')).toBeNull();
  });

  it('keeps the alerts on when the API cannot remove the device', async () => {
    serveDevices();
    server.use(http.delete(apiUrl('/devices/push-token'), () => HttpResponse.json({ message: 'Server Error' }, { status: 500 })));

    await openApp();
    await act(() => router.push('/account'));
    const toggle = await screen.findByRole('switch', { name: 'Avisos no celular' });
    await waitFor(() => expect(SecureStore.getItemAsync('pulseboard.push_token')).resolves.toBe(TOKEN));

    await fireEvent(toggle, 'valueChange', false);

    expect(await screen.findByText('Não foi possível atualizar as notificações. Verifique sua conexão e tente de novo.')).toBeOnTheScreen();
    expect(screen.getByRole('switch', { name: 'Avisos no celular' })).toHaveProp('value', true);
    expect(await SecureStore.getItemAsync('pulseboard.push_disabled')).toBeNull();
  });

  it('points to the system settings once the permission is blocked', async () => {
    jest.mocked(Notifications.getPermissionsAsync).mockImplementation(async () => BLOCKED);
    const requests = serveDevices();

    await openApp();
    await act(() => router.push('/account'));

    expect(
      await screen.findByText('As notificações estão bloqueadas para o PulseBoard. Libere nas configurações do celular.'),
    ).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Abrir configurações' })).toBeOnTheScreen();
    expect(screen.getByRole('switch', { name: 'Avisos no celular' })).toHaveProp('value', false);
    expect(requests).toHaveLength(0);
    expect(Notifications.requestPermissionsAsync).not.toHaveBeenCalled();
  });
});

describe('tapping an alert', () => {
  it('opens the transactions in the organization the alert came from', async () => {
    const organizations: (string | null)[] = [];
    serveDevices([demoStore, secondStore]);
    server.use(
      http.get(apiUrl('/transactions'), ({ request }) => {
        organizations.push(request.headers.get('x-organization-id'));

        return HttpResponse.json(transactionsPage([]));
      }),
    );
    tap({ url: '/transactions', organization_id: secondStore.id, kind: 'daily_goal_reached' });

    await storeSession();
    await renderRouter('./app', { initialUrl: '/' });

    expect(await screen.findByText('Nenhuma transação no período')).toBeOnTheScreen();
    await waitFor(() => expect(organizations).toContain(secondStore.id));
    expect(organizations).not.toContain(demoStore.id);
    expect(Notifications.clearLastNotificationResponse).toHaveBeenCalled();
  });

  it('ignores a screen outside the allowlist and an organization the user is not a member of', async () => {
    const requests: (string | null)[] = [];
    serveDevices();
    server.use(
      http.get(apiUrl('/dashboard'), ({ request }) => {
        requests.push(request.headers.get('x-organization-id'));

        return HttpResponse.json({ message: 'stop' }, { status: 503 });
      }),
    );
    tap({ url: '/account/../settings', organization_id: secondStore.id, kind: 'stock_low' });

    await storeSession();
    await renderRouter('./app', { initialUrl: '/' });

    await waitFor(() => expect(Notifications.clearLastNotificationResponse).toHaveBeenCalled());
    await waitFor(() => expect(requests.length).toBeGreaterThan(0));
    expect(screen.queryByText('Nenhuma transação no período')).not.toBeOnTheScreen();
    expect(requests.every((organization) => organization === demoStore.id)).toBe(true);
  });
});
