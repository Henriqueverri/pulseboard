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

export interface IssuedToken extends AuthProfile {
  token: string;
  token_type: 'Bearer';
  expires_at: IsoDateTime;
}
