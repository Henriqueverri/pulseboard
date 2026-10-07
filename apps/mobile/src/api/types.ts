/** Response shapes of the PulseBoard API (`docs/api.md`). */

export type IsoDateTime = string;

export interface User {
  id: string;
  name: string;
  email: string;
}

export interface Organization {
  id: string;
  name: string;
  slug: string;
  currency: string;
  /** IANA timezone: the business calendar of every date range and bucket. */
  timezone: string;
  role?: 'owner' | 'member';
}

/** `GET /auth/me`; also embedded in the `POST /auth/tokens` response. */
export interface AuthProfile {
  user: User;
  organizations: Organization[];
  current_organization: Organization | null;
}

/** Every analytics metric: `change` is a percentage with one decimal, `null` without a comparable base. */
export interface Comparison<T> {
  value: T;
  previous: T;
  change: number | null;
}

/** Money is always a decimal string ("1250.00"), never a float. */
export type Decimal = string;

export interface PeriodRange {
  from: string;
  to: string;
  days: number;
}

export interface AnalyticsMeta {
  period: PeriodRange;
  previous_period: PeriodRange;
  timezone: string;
  currency: string;
}

export interface DashboardMetrics {
  revenue: Comparison<Decimal>;
  orders: Comparison<number>;
  /** `null` when there are no orders. */
  average_order_value: Comparison<Decimal | null>;
  customers: Comparison<number>;
}

export interface DashboardResponse {
  data: DashboardMetrics;
  meta: AnalyticsMeta;
}

export interface RevenueBucket {
  bucket: string;
  from: string;
  to: string;
  revenue: Decimal;
  orders: number;
}

export interface RevenueResponse {
  data: RevenueBucket[];
  summary: {
    revenue: Comparison<Decimal>;
    orders: Comparison<number>;
  };
  meta: AnalyticsMeta & { granularity: 'day' | 'week' | 'month' };
}

/** Laravel paginator envelope; only the `meta` fields the app reads. */
export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export type TransactionStatus = 'paid' | 'refunded' | 'pending' | 'canceled';

/** Where a transaction (or one of its status changes) came from: the demo seed or the ingestion API. */
export type TransactionSource = 'seed' | 'ingest';

export interface TransactionCustomer {
  id: string;
  name: string;
  email: string;
  is_deleted: boolean;
}

interface TransactionRecord {
  id: string;
  /** The integrating system's id; null for seeded transactions. */
  external_id: string | null;
  source: TransactionSource;
  status: TransactionStatus;
  total_amount: Decimal;
  occurred_at: IsoDateTime;
  customer: TransactionCustomer;
}

/** `GET /transactions` row. */
export interface Transaction extends TransactionRecord {
  items_count: number;
}

export interface TransactionItem {
  id: string;
  quantity: number;
  /** Price at purchase time, not the product's current price. */
  unit_price: Decimal;
  line_total: Decimal;
  product: {
    id: string;
    name: string;
    sku: string | null;
    is_deleted: boolean;
  };
}

/**
 * One lifecycle step: `from_status` is null on creation; `occurred_at` is the business time
 * reported by the source, `recorded_at` when PulseBoard stored it.
 */
export interface TransactionStatusChange {
  from_status: TransactionStatus | null;
  to_status: TransactionStatus;
  occurred_at: IsoDateTime;
  recorded_at: IsoDateTime;
  source: TransactionSource;
}

/** `GET /transactions/{id}`: `status_history` comes in lifecycle order. */
export interface TransactionDetail extends TransactionRecord {
  items: TransactionItem[];
  status_history: TransactionStatusChange[];
}

export interface IssuedToken extends AuthProfile {
  token: string;
  token_type: 'Bearer';
  expires_at: IsoDateTime;
}
