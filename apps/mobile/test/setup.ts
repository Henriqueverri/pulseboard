import { defaultHandlers } from './handlers';
import { server } from './server';

/** In-memory Keychain/Keystore: the native module does not exist under Jest. */
jest.mock('expo-secure-store', () => {
  const store = new Map<string, string>();

  return {
    __store: store,
    getItemAsync: jest.fn(async (key: string) => store.get(key) ?? null),
    setItemAsync: jest.fn(async (key: string, value: string) => {
      store.set(key, value);
    }),
    deleteItemAsync: jest.fn(async (key: string) => {
      store.delete(key);
    }),
  };
});

/**
 * Permission granted and a fixed token by default; `__setLastResponse` simulates tapping an alert
 * (set it before rendering).
 */
jest.mock('expo-notifications', () => {
  const state: { lastResponse: unknown } = { lastResponse: null };

  return {
    __setLastResponse: (response: unknown) => {
      state.lastResponse = response;
    },
    DEFAULT_ACTION_IDENTIFIER: 'expo.modules.notifications.actions.DEFAULT',
    AndroidImportance: { HIGH: 4 },
    setNotificationHandler: jest.fn(),
    setNotificationChannelAsync: jest.fn(async () => null),
    getPermissionsAsync: jest.fn(async () => ({ granted: true, canAskAgain: true, status: 'granted' })),
    requestPermissionsAsync: jest.fn(async () => ({ granted: true, canAskAgain: true, status: 'granted' })),
    getExpoPushTokenAsync: jest.fn(async () => ({ type: 'expo', data: 'ExponentPushToken[test-device]' })),
    useLastNotificationResponse: jest.fn(() => state.lastResponse),
    clearLastNotificationResponse: jest.fn(() => {
      state.lastResponse = null;
    }),
  };
});

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));

beforeEach(() => server.use(...defaultHandlers));

afterEach(() => {
  server.resetHandlers();
  (jest.requireMock('expo-secure-store') as { __store: Map<string, string> }).__store.clear();
  (jest.requireMock('expo-notifications') as { __setLastResponse: (response: unknown) => void }).__setLastResponse(null);
});

afterAll(() => server.close());
