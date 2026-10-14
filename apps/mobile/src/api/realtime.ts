import { apiRequest } from './client';
import type { RealtimeConfig } from './types';

export async function getRealtimeConfig(): Promise<RealtimeConfig> {
  return (await apiRequest<{ data: RealtimeConfig }>('/realtime')).data;
}

/** Signs a `private-organization.{id}` subscription with the Bearer token; members only (403 otherwise). */
export function authorizeChannel(socketId: string, channelName: string): Promise<{ auth: string }> {
  return apiRequest<{ auth: string }>('/broadcasting/auth', {
    method: 'POST',
    body: { socket_id: socketId, channel_name: channelName },
  });
}
