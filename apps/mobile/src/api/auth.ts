import { apiRequest } from './client';
import type { AuthProfile, IssuedToken } from './types';

/** The first request may hit a cold-started API (around a minute on Render Free). */
const LOGIN_TIMEOUT_MS = 70_000;

export interface Credentials {
  email: string;
  password: string;
}

export function issueToken(credentials: Credentials, deviceName: string): Promise<IssuedToken> {
  return apiRequest<IssuedToken>('/auth/tokens', {
    method: 'POST',
    body: { ...credentials, device_name: deviceName },
    authenticated: false,
    timeoutMs: LOGIN_TIMEOUT_MS,
  });
}

export function getProfile(signal?: AbortSignal): Promise<AuthProfile> {
  return apiRequest<AuthProfile>('/auth/me', { signal });
}

export function revokeCurrentToken(): Promise<null> {
  return apiRequest<null>('/auth/tokens/current', { method: 'DELETE' });
}
