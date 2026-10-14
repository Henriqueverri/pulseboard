import type { ResourceEnvelope } from '~/types/api'
import type { RealtimeConfig } from '~/types/realtime'

/** Both calls are per user, not per organization: the channel name carries the organization. */
export function useRealtimeRepository() {
  const { apiFetch } = useApiClient()

  return {
    config: async () =>
      (await apiFetch<ResourceEnvelope<RealtimeConfig>>('/realtime', { organizationId: null })).data,

    /** Signs a `private-organization.{id}` subscription; members only (403 otherwise). */
    authorize: (socketId: string, channelName: string) =>
      apiFetch<{ auth: string }>('/broadcasting/auth', {
        method: 'POST',
        body: { socket_id: socketId, channel_name: channelName },
        organizationId: null,
      }),
  }
}
