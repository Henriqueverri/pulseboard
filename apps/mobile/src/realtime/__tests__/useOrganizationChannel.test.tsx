import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook } from '@testing-library/react-native';
import type { ReactNode } from 'react';
import { AppState, type AppStateStatus } from 'react-native';

import { authorizeChannel, getRealtimeConfig } from '@/api/realtime';
import type { RealtimeConfig } from '@/api/types';

import { createEcho } from '../createEcho';
import { LIVE_REFRESH_DEBOUNCE_MS, useOrganizationChannel } from '../useOrganizationChannel';

jest.mock('@/api/realtime', () => ({ getRealtimeConfig: jest.fn(), authorizeChannel: jest.fn() }));
jest.mock('../createEcho', () => ({ createEcho: jest.fn() }));

const ORG = '0199a000-0000-7000-8000-00000000000a';
const ENABLED: RealtimeConfig = {
  enabled: true,
  key: 'public-key',
  host: 'api.example.test',
  port: 443,
  scheme: 'https',
  auth_endpoint: '/api/v1/broadcasting/auth',
};

type Listener = (payload: unknown) => void;

/** Just enough of Echo: private channels with listeners and `disconnect`. */
function fakeEcho() {
  const listeners = new Map<string, Listener>();
  const channel: { listen: jest.Mock } = {
    listen: jest.fn((event: string, callback: Listener) => {
      listeners.set(event, callback);
      return channel;
    }),
  };

  let onStatus: (status: string) => void = () => undefined;

  return {
    instance: {
      private: jest.fn(() => channel),
      disconnect: jest.fn(),
      connector: {
        onConnectionChange: jest.fn((callback: (status: string) => void) => {
          onStatus = callback;
          return () => undefined;
        }),
      },
    },
    emit: (event: string) => listeners.get(event)?.({}),
    setStatus: (status: string) => onStatus(status),
  };
}

let onAppState: (status: AppStateStatus) => void = () => undefined;
const removeAppState = jest.fn();

// React Native's Jest mock replaces `AppState.currentState` with a function.
const currentStateDescriptor = Object.getOwnPropertyDescriptor(AppState, 'currentState');

function setAppState(status: AppStateStatus) {
  Object.defineProperty(AppState, 'currentState', { value: status, configurable: true, writable: true });
}

function setup() {
  const queryClient = new QueryClient();
  const invalidate = jest.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );

  return { invalidate, wrapper };
}

/** Lets the config request (a resolved mock) settle. */
async function settle() {
  await act(async () => undefined);
}

describe('useOrganizationChannel', () => {
  let echo: ReturnType<typeof fakeEcho>;

  beforeEach(() => {
    echo = fakeEcho();
    jest.mocked(getRealtimeConfig).mockReset().mockResolvedValue(ENABLED);
    jest.mocked(createEcho).mockReset().mockReturnValue(echo.instance as never);
    setAppState('active');
    jest.spyOn(AppState, 'addEventListener').mockImplementation((_, listener) => {
      onAppState = listener;
      return { remove: removeAppState };
    });
  });

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
    if (currentStateDescriptor) {
      Object.defineProperty(AppState, 'currentState', currentStateDescriptor);
    }
  });

  it('subscribes to the organization channel with the Bearer authorizer', async () => {
    const { wrapper } = setup();

    await renderHook(() => useOrganizationChannel(ORG), { wrapper });
    await settle();

    expect(createEcho).toHaveBeenCalledWith(ENABLED, authorizeChannel);
    expect(echo.instance.private).toHaveBeenCalledWith(`organization.${ORG}`);
  });

  it('invalidates the organization queries once per burst of events', async () => {
    const { invalidate, wrapper } = setup();
    await renderHook(() => useOrganizationChannel(ORG), { wrapper });
    await settle();
    jest.useFakeTimers();

    echo.emit('.transaction.recorded');
    echo.emit('.stock.changed');
    jest.advanceTimersByTime(LIVE_REFRESH_DEBOUNCE_MS - 1);
    expect(invalidate).not.toHaveBeenCalled();

    echo.emit('.transaction.status-changed');
    jest.advanceTimersByTime(LIVE_REFRESH_DEBOUNCE_MS);

    expect(invalidate).toHaveBeenCalledTimes(1);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['org', ORG] });
  });

  it('catches up once the connection comes back after a network drop', async () => {
    const { invalidate, wrapper } = setup();
    await renderHook(() => useOrganizationChannel(ORG), { wrapper });
    await settle();
    jest.useFakeTimers();

    echo.setStatus('connected');
    jest.advanceTimersByTime(LIVE_REFRESH_DEBOUNCE_MS);
    expect(invalidate).not.toHaveBeenCalled();

    echo.setStatus('reconnecting');
    echo.setStatus('connected');
    jest.advanceTimersByTime(LIVE_REFRESH_DEBOUNCE_MS);

    expect(invalidate).toHaveBeenCalledTimes(1);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['org', ORG] });
  });

  it('closes the socket in the background and reconnects in the foreground', async () => {
    const { wrapper } = setup();
    await renderHook(() => useOrganizationChannel(ORG), { wrapper });
    await settle();

    await act(async () => onAppState('background'));
    expect(echo.instance.disconnect).toHaveBeenCalledTimes(1);

    await act(async () => onAppState('active'));
    await settle();
    expect(createEcho).toHaveBeenCalledTimes(2);
  });

  it('does not connect while the app starts in the background', async () => {
    setAppState('background');
    const { wrapper } = setup();

    await renderHook(() => useOrganizationChannel(ORG), { wrapper });
    await settle();

    expect(getRealtimeConfig).not.toHaveBeenCalled();
    expect(createEcho).not.toHaveBeenCalled();
  });

  it('stays off when the API has no realtime server or cannot be reached', async () => {
    const { wrapper } = setup();
    jest.mocked(getRealtimeConfig).mockResolvedValueOnce({ enabled: false });
    await renderHook(() => useOrganizationChannel(ORG), { wrapper });
    await settle();

    jest.mocked(getRealtimeConfig).mockRejectedValueOnce(new Error('Network request failed'));
    await act(async () => onAppState('active'));
    await settle();

    expect(createEcho).not.toHaveBeenCalled();
  });

  it('disconnects and stops listening when the session leaves the organization', async () => {
    const { wrapper } = setup();
    const { unmount } = await renderHook(() => useOrganizationChannel(ORG), { wrapper });
    await settle();

    expect(createEcho).toHaveBeenCalledTimes(1);
    await unmount();

    expect(echo.instance.disconnect).toHaveBeenCalledTimes(1);
    expect(removeAppState).toHaveBeenCalled();
  });
});
