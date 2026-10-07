import type { AuthProfile, IssuedToken, Organization } from '@/api/types';

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
