import { useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import { AppState } from 'react-native';

import { authorizeChannel, getRealtimeConfig } from '@/api/realtime';

import { createEcho, type OrganizationEcho } from './createEcho';

/** A POS sale moves the transaction and several stock rows: refetch once, after the burst. */
export const LIVE_REFRESH_DEBOUNCE_MS = 1000;

const LIVE_EVENTS = ['.transaction.recorded', '.transaction.status-changed', '.stock.changed'];

/**
 * Keeps the organization's screens fresh while the app is in the foreground: every sale or
 * stock event on `private-organization.{id}` invalidates `['org', id]`. In the background the
 * socket is closed; coming back reconnects, and the queries refetch on focus anyway.
 */
export function useOrganizationChannel(organizationId: string): void {
  const queryClient = useQueryClient();

  useEffect(() => {
    const channel = `organization.${organizationId}`;
    let echo: OrganizationEcho | null = null;
    let connecting = false;
    let disposed = false;
    let refreshTimer: ReturnType<typeof setTimeout> | null = null;

    const refresh = () => {
      if (refreshTimer) {
        clearTimeout(refreshTimer);
      }

      refreshTimer = setTimeout(() => {
        refreshTimer = null;
        void queryClient.invalidateQueries({ queryKey: ['org', organizationId] });
      }, LIVE_REFRESH_DEBOUNCE_MS);
    };

    async function connect() {
      if (echo || connecting || disposed) {
        return;
      }

      connecting = true;

      try {
        const config = await getRealtimeConfig();

        if (!config.enabled || disposed || AppState.currentState !== 'active') {
          return;
        }

        echo = createEcho(config, authorizeChannel);
        const subscription = echo.private(channel);
        LIVE_EVENTS.forEach((event) => subscription.listen(event, refresh));

        // Events sent while the socket was down (network loss) are gone: catch up once it is back.
        let dropped = false;
        echo.connector.onConnectionChange((status) => {
          if (status === 'reconnecting' || status === 'disconnected' || status === 'failed') {
            dropped = true;
          } else if (status === 'connected' && dropped) {
            dropped = false;
            refresh();
          }
        });
      } catch {
        // No realtime (API unreachable): the screens still work and refetch on focus.
      } finally {
        connecting = false;
      }
    }

    function disconnect() {
      echo?.disconnect();
      echo = null;
    }

    if (AppState.currentState === 'active') {
      void connect();
    }

    const appState = AppState.addEventListener('change', (status) => {
      if (status === 'active') {
        void connect();
      } else {
        disconnect();
      }
    });

    return () => {
      disposed = true;
      appState.remove();

      if (refreshTimer) {
        clearTimeout(refreshTimer);
      }

      disconnect();
    };
  }, [organizationId, queryClient]);
}
