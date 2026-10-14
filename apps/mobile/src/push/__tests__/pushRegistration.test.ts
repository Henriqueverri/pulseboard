import Constants, { ExecutionEnvironment } from 'expo-constants';
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { http, HttpResponse } from 'msw';
import { Platform } from 'react-native';

import { apiUrl, server } from '@test/server';

import { ANDROID_CHANNEL_ID, currentPushStatus, registerForPush, unregisterFromPush } from '../pushRegistration';

const NOT_ASKED = { granted: false, canAskAgain: true, status: 'undetermined' } as Notifications.NotificationPermissionsStatus;
const GRANTED = { granted: true, canAskAgain: true, status: 'granted' } as Notifications.NotificationPermissionsStatus;
const BLOCKED = { granted: false, canAskAgain: false, status: 'denied' } as Notifications.NotificationPermissionsStatus;

beforeEach(() => jest.clearAllMocks());

afterEach(() => {
  jest.restoreAllMocks();
  jest.mocked(Notifications.getPermissionsAsync).mockImplementation(async () => GRANTED);
  jest.mocked(Notifications.requestPermissionsAsync).mockImplementation(async () => GRANTED);
});

describe('registerForPush', () => {
  it('creates the Android channel before asking for permission, then registers the token', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    jest.mocked(Notifications.getPermissionsAsync).mockImplementation(async () => NOT_ASKED);
    let body: unknown = null;
    server.use(
      http.post(apiUrl('/devices/push-token'), async ({ request }) => {
        body = await request.json();

        return new HttpResponse(null, { status: 204 });
      }),
    );

    await expect(registerForPush()).resolves.toBe('registered');

    expect(Notifications.setNotificationChannelAsync).toHaveBeenCalledWith(ANDROID_CHANNEL_ID, expect.objectContaining({ name: 'Alertas' }));
    expect(Notifications.requestPermissionsAsync).toHaveBeenCalled();
    expect(body).toEqual({ token: 'ExponentPushToken[test-device]', platform: 'android' });
    expect(await SecureStore.getItemAsync('pulseboard.push_token')).toBe('ExponentPushToken[test-device]');
  });

  it('does not prompt once the system stopped asking', async () => {
    jest.mocked(Notifications.getPermissionsAsync).mockImplementation(async () => BLOCKED);

    await expect(registerForPush()).resolves.toBe('denied');
    await expect(currentPushStatus()).resolves.toBe('denied');
    expect(Notifications.requestPermissionsAsync).not.toHaveBeenCalled();
    expect(Notifications.getExpoPushTokenAsync).not.toHaveBeenCalled();
  });

  it('reports a refused prompt as denied without registering', async () => {
    jest.mocked(Notifications.getPermissionsAsync).mockImplementation(async () => NOT_ASKED);
    jest.mocked(Notifications.requestPermissionsAsync).mockImplementation(async () => NOT_ASKED);

    await expect(registerForPush()).resolves.toBe('denied');
    await expect(currentPushStatus()).resolves.toBeNull();
    expect(Notifications.getExpoPushTokenAsync).not.toHaveBeenCalled();
  });

  it('is unsupported in Expo Go on Android, without touching the native module', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    jest.replaceProperty(Constants, 'executionEnvironment', ExecutionEnvironment.StoreClient);

    await expect(registerForPush()).resolves.toBe('unsupported');
    await expect(currentPushStatus()).resolves.toBe('unsupported');
    expect(Notifications.getExpoPushTokenAsync).not.toHaveBeenCalled();
  });

  it('fails (keeping no token) when the API is unreachable', async () => {
    server.use(http.post(apiUrl('/devices/push-token'), () => HttpResponse.error()));

    await expect(registerForPush()).resolves.toBe('failed');
    expect(await SecureStore.getItemAsync('pulseboard.push_token')).toBeNull();
  });
});

describe('unregisterFromPush', () => {
  it('has nothing to remove when no token was registered', async () => {
    await expect(unregisterFromPush()).resolves.toBe(true);
  });

  it('keeps the token when the API could not remove the device', async () => {
    await SecureStore.setItemAsync('pulseboard.push_token', 'ExponentPushToken[test-device]');
    server.use(http.delete(apiUrl('/devices/push-token'), () => HttpResponse.json({}, { status: 503 })));

    await expect(unregisterFromPush()).resolves.toBe(false);
    expect(await SecureStore.getItemAsync('pulseboard.push_token')).toBe('ExponentPushToken[test-device]');
  });
});
