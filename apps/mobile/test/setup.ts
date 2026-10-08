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

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));

beforeEach(() => server.use(...defaultHandlers));

afterEach(() => {
  server.resetHandlers();
  (jest.requireMock('expo-secure-store') as { __store: Map<string, string> }).__store.clear();
});

afterAll(() => server.close());
