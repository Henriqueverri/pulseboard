import type { CivilRange, Granularity } from '@/lib/period';

import { apiRequest } from './client';
import type { InventoryResponse, RevenueResponse } from './types';

export function getRevenue(range: CivilRange, granularity: Granularity, signal?: AbortSignal): Promise<RevenueResponse> {
  return apiRequest<RevenueResponse>('/analytics/revenue', { query: { ...range, granularity }, signal });
}

export function getInventory(signal?: AbortSignal): Promise<InventoryResponse> {
  return apiRequest<InventoryResponse>('/analytics/inventory', { signal });
}
