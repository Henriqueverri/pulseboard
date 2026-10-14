import Echo from 'laravel-echo';
// Metro resolves the React Native build (the `react-native` field), which watches NetInfo.
import Pusher from 'pusher-js';

import type { EnabledRealtimeConfig } from '@/api/types';

export type OrganizationEcho = Echo<'reverb'>;

export type ChannelAuthorizer = (socketId: string, channelName: string) => Promise<{ auth: string }>;

/** Echo over Reverb; channel authorization goes through the API client (Bearer token). */
export function createEcho(config: EnabledRealtimeConfig, authorize: ChannelAuthorizer): OrganizationEcho {
  return new Echo({
    broadcaster: 'reverb',
    key: config.key,
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    Pusher,
    channelAuthorization: {
      endpoint: config.auth_endpoint,
      transport: 'ajax',
      customHandler: ({ socketId, channelName }, callback) => {
        authorize(socketId, channelName).then(
          (data) => callback(null, data),
          (error: unknown) => callback(error instanceof Error ? error : new Error(String(error)), null),
        );
      },
    },
  });
}
