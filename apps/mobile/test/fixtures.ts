import type {
  AuthProfile,
  DashboardResponse,
  IssuedToken,
  Organization,
  Paginated,
  RevenueResponse,
  Transaction,
  TransactionDetail,
} from '@/api/types';

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

export function transactionId(index: number): string {
  return `7c1e0000-0000-4000-8000-${String(index).padStart(12, '0')}`;
}

export function transaction(index: number, overrides: Partial<Transaction> = {}): Transaction {
  return {
    id: transactionId(index),
    external_id: null,
    source: 'seed',
    status: 'paid',
    total_amount: '150.00',
    occurred_at: '2026-09-15T14:32:00.000000Z',
    items_count: 2,
    customer: { id: '5b2e0000-0000-4000-8000-000000000001', name: `Cliente ${index}`, email: `cliente${index}@example.com`, is_deleted: false },
    ...overrides,
  };
}

export function transactionsPage(data: Transaction[], currentPage = 1, lastPage = 1, total = data.length): Paginated<Transaction> {
  return { data, meta: { current_page: currentPage, last_page: lastPage, per_page: 20, total } };
}

/** Received through the ingestion API, then refunded: the full `null → paid → refunded` chain. */
export const INGESTED_TRANSACTION: TransactionDetail = {
  id: transactionId(42),
  external_id: 'pos-1042',
  source: 'ingest',
  status: 'refunded',
  total_amount: '259.80',
  occurred_at: '2026-09-15T14:32:00.000000Z',
  customer: { id: '5b2e0000-0000-4000-8000-000000000042', name: 'Ana Souza', email: 'ana@example.com', is_deleted: false },
  items: [
    {
      id: '3f4a0000-0000-4000-8000-000000000001',
      quantity: 2,
      unit_price: '99.90',
      line_total: '199.80',
      product: { id: '2a1b0000-0000-4000-8000-000000000001', name: 'Fone Bluetooth', sku: 'FON-01', is_deleted: false },
    },
    {
      id: '3f4a0000-0000-4000-8000-000000000002',
      quantity: 1,
      unit_price: '60.00',
      line_total: '60.00',
      product: { id: '2a1b0000-0000-4000-8000-000000000002', name: 'Capa', sku: null, is_deleted: true },
    },
  ],
  status_history: [
    {
      from_status: null,
      to_status: 'paid',
      occurred_at: '2026-09-15T14:32:00.000000Z',
      recorded_at: '2026-09-15T14:32:01.000000Z',
      source: 'ingest',
    },
    {
      from_status: 'paid',
      to_status: 'refunded',
      occurred_at: '2026-09-16T12:00:00.000000Z',
      recorded_at: '2026-09-16T18:30:00.000000Z',
      source: 'ingest',
    },
  ],
};
