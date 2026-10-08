import type { CivilRange } from '@/lib/period';

import { apiRequest } from './client';
import type { DashboardResponse } from './types';

export function getDashboard(range: CivilRange, signal?: AbortSignal): Promise<DashboardResponse> {
  return apiRequest<DashboardResponse>('/dashboard', { query: { ...range }, signal });
}
