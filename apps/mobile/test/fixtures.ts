import type { AuthProfile, DashboardResponse, IssuedToken, Organization, RevenueResponse } from '@/api/types';

/** Shapes copied from real API responses (`docs/api.md`), ids shortened. */
export const demoStore: Organization = {
  id: '9d1a0000-0000-4000-8000-000000000001',
  name: 'PulseBoard Demo Store',
  slug: 'pulseboard-demo',
  currency: 'BRL',
  timezone: 'America/Sao_Paulo',
  role: 'owner',
};

export const secondStore: Organization = {
  id: '9d1a0000-0000-4000-8000-000000000002',
  name: 'Loja Centro',
  slug: 'loja-centro',
  currency: 'BRL',
  timezone: 'America/Sao_Paulo',
  role: 'member',
};

export function profile(organizations: Organization[] = [demoStore]): AuthProfile {
  return {
    user: { id: '01a10000-0000-4000-8000-0000000000aa', name: 'Demo Owner', email: 'demo@example.com' },
    organizations,
    current_organization: organizations[0] ?? null,
  };
}

export function issuedToken(organizations: Organization[] = [demoStore], token = '12|pbm_newtoken'): IssuedToken {
  return {
    token,
    token_type: 'Bearer',
    expires_at: '2026-11-06T11:00:00.000000Z',
    ...profile(organizations),
  };
}

const ANALYTICS_META = {
  period: { from: '2026-09-01', to: '2026-09-30', days: 30 },
  previous_period: { from: '2026-08-02', to: '2026-08-31', days: 30 },
  timezone: 'America/Sao_Paulo',
  currency: 'BRL',
};

export const DASHBOARD: DashboardResponse = {
  data: {
    revenue: { value: '12500.00', previous: '10200.00', change: 22.5 },
    orders: { value: 98, previous: 81, change: 21 },
    average_order_value: { value: '127.55', previous: '125.93', change: 1.3 },
    customers: { value: 52, previous: 47, change: 10.6 },
  },
  meta: ANALYTICS_META,
};

export const EMPTY_DASHBOARD: DashboardResponse = {
  data: {
    revenue: { value: '0.00', previous: '0.00', change: 0 },
    orders: { value: 0, previous: 0, change: 0 },
    average_order_value: { value: null, previous: null, change: null },
    customers: { value: 0, previous: 0, change: 0 },
  },
  meta: ANALYTICS_META,
};

export const REVENUE: RevenueResponse = {
  data: [
    { bucket: '2026-09-01', from: '2026-09-01', to: '2026-09-01', revenue: '400.00', orders: 3 },
    { bucket: '2026-09-02', from: '2026-09-02', to: '2026-09-02', revenue: '900.00', orders: 7 },
  ],
  summary: { revenue: DASHBOARD.data.revenue, orders: DASHBOARD.data.orders },
  meta: { ...ANALYTICS_META, granularity: 'day' },
};
