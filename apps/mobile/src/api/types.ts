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

export interface IssuedToken extends AuthProfile {
  token: string;
  token_type: 'Bearer';
  expires_at: IsoDateTime;
}
