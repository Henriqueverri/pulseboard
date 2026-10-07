import { apiRequest } from './client';
import type { HealthResponse } from './types';

export function getHealth(signal?: AbortSignal): Promise<HealthResponse> {
  return apiRequest<HealthResponse>('/health', { signal, authenticated: false });
}
