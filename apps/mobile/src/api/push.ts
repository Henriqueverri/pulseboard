import { apiRequest } from './client';

export type PushPlatform = 'android' | 'ios';

/** Tied to the current access token on the API: signing out (revoking it) also removes the device. */
export function registerPushToken(token: string, platform: PushPlatform): Promise<null> {
  return apiRequest<null>('/devices/push-token', { method: 'POST', body: { token, platform } });
}

export function unregisterPushToken(token: string): Promise<null> {
  return apiRequest<null>('/devices/push-token', { method: 'DELETE', body: { token } });
}
