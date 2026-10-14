import type Echo from 'laravel-echo'
import type { EnabledRealtimeConfig } from '~/types/realtime'

export type OrganizationEcho = Echo<'reverb'>

export type ChannelAuthorizer = (socketId: string, channelName: string) => Promise<{ auth: string }>

/**
 * Echo over Reverb. The libraries load on demand, outside the initial bundle; channel
 * authorization goes through the API client (session cookie, XSRF) instead of Pusher's own XHR.
 */
export async function createEcho(config: EnabledRealtimeConfig, authorize: ChannelAuthorizer): Promise<OrganizationEcho> {
  const [{ default: EchoClient }, { default: Pusher }] = await Promise.all([
    import('laravel-echo'),
    import('pusher-js'),
  ])

  return new EchoClient({
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
          data => callback(null, data),
          error => callback(error instanceof Error ? error : new Error(String(error)), null),
        )
      },
    },
  })
}
