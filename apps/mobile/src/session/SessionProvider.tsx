import { useQueryClient } from '@tanstack/react-query';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

import { getProfile, issueToken, revokeCurrentToken, type Credentials } from '@/api/auth';
import { configureApiSession } from '@/api/client';
import { isApiError } from '@/api/errors';
import type { AuthProfile, Organization, User } from '@/api/types';

import { getDeviceName } from './deviceName';
import { clearStoredSession, loadStoredSession, saveStoredSession } from './storage';

export type SessionStatus =
  /** Reading SecureStore and validating the stored token. */
  | 'restoring'
  /** A token is stored but the API could not be reached to validate it. */
  | 'unavailable'
  | 'signedOut'
  /** Authenticated, but the user belongs to several organizations and none is active. */
  | 'selectingOrganization'
  | 'signedIn';

interface SessionState {
  status: SessionStatus;
  user: User | null;
  organizations: Organization[];
  organization: Organization | null;
  /** Message for the next screen (expired session, lost access to an organization). */
  notice: string | null;
  restoreError: unknown;
}

export interface SessionContextValue extends SessionState {
  signIn: (credentials: Credentials) => Promise<void>;
  signOut: () => Promise<void>;
  selectOrganization: (organizationId: string) => Promise<void>;
  retryRestore: () => void;
}

const SIGNED_OUT: SessionState = {
  status: 'signedOut',
  user: null,
  organizations: [],
  organization: null,
  notice: null,
  restoreError: null,
};

export const SESSION_EXPIRED_NOTICE = 'Sua sessão expirou. Entre novamente.';
export const ORGANIZATION_DENIED_NOTICE = 'Você não tem mais acesso a essa organização. Escolha outra.';

/** The preferred organization if still a member; the only one if there is exactly one; otherwise the user picks. */
function resolveOrganization(organizations: Organization[], preferredId: string | null): Organization | null {
  const preferred = preferredId ? organizations.find((organization) => organization.id === preferredId) : undefined;

  if (preferred) {
    return preferred;
  }

  return organizations.length === 1 ? (organizations[0] ?? null) : null;
}

const SessionContext = createContext<SessionContextValue | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [state, setState] = useState<SessionState>({ ...SIGNED_OUT, status: 'restoring' });
  const tokenRef = useRef<string | null>(null);

  /** Activates a profile: the API client, SecureStore and the cache all follow the same organization. */
  const activate = useCallback(
    async (token: string, profile: AuthProfile, preferredOrganizationId: string | null, notice: string | null = null) => {
      const organization = resolveOrganization(profile.organizations, preferredOrganizationId);

      tokenRef.current = token;
      configureApiSession({ token, organizationId: organization?.id ?? null });
      await saveStoredSession({ token, organizationId: organization?.id ?? null });

      setState({
        status: organization ? 'signedIn' : 'selectingOrganization',
        user: profile.user,
        organizations: profile.organizations,
        organization,
        notice,
        restoreError: null,
      });
    },
    [],
  );

  const endSession = useCallback(
    async (notice: string | null) => {
      tokenRef.current = null;
      configureApiSession({ token: null, organizationId: null });
      queryClient.clear();
      await clearStoredSession();
      setState({ ...SIGNED_OUT, notice });
    },
    [queryClient],
  );

  const restore = useCallback(async () => {
    setState((current) => ({ ...current, status: 'restoring', restoreError: null }));

    const stored = await loadStoredSession();

    if (!stored) {
      setState(SIGNED_OUT);
      return;
    }

    tokenRef.current = stored.token;
    configureApiSession({ token: stored.token, organizationId: stored.organizationId });

    try {
      const profile = await getProfile();
      await activate(stored.token, profile, stored.organizationId);
    } catch (error) {
      // A 401 already ended the session through onUnauthorized.
      if (isApiError(error) && error.isUnauthorized) {
        return;
      }

      setState((current) => ({ ...current, status: 'unavailable', restoreError: error }));
    }
  }, [activate]);

  const handleOrganizationDenied = useCallback(async () => {
    const token = tokenRef.current;

    if (!token) {
      return;
    }

    configureApiSession({ organizationId: null });
    queryClient.clear();

    try {
      await activate(token, await getProfile(), null, ORGANIZATION_DENIED_NOTICE);
    } catch {
      // A 401 ends the session; otherwise the user can still pick from the known list.
      setState((current) => ({
        ...current,
        status: current.status === 'signedOut' ? current.status : 'selectingOrganization',
        organization: null,
        notice: ORGANIZATION_DENIED_NOTICE,
      }));
    }
  }, [activate, queryClient]);

  useEffect(() => {
    configureApiSession({
      onUnauthorized: () => void endSession(SESSION_EXPIRED_NOTICE),
      onOrganizationDenied: () => void handleOrganizationDenied(),
    });
    void restore();

    return () => configureApiSession({ token: null, organizationId: null, onUnauthorized: null, onOrganizationDenied: null });
  }, [endSession, handleOrganizationDenied, restore]);

  const signIn = useCallback(
    async (credentials: Credentials) => {
      const issued = await issueToken(credentials, await getDeviceName());

      queryClient.clear();
      await activate(issued.token, issued, null);
    },
    [activate, queryClient],
  );

  const signOut = useCallback(async () => {
    try {
      await revokeCurrentToken();
    } catch {
      // Already expired/revoked, or the API is unreachable: the device forgets the token either way.
    }

    await endSession(null);
  }, [endSession]);

  const selectOrganization = useCallback(
    async (organizationId: string) => {
      const token = tokenRef.current;
      const organization = state.organizations.find((candidate) => candidate.id === organizationId);

      if (!token || !organization) {
        return;
      }

      configureApiSession({ organizationId });
      await saveStoredSession({ token, organizationId });
      // Same as the web: nothing cached for one organization may show up in another.
      queryClient.clear();
      setState((current) => ({ ...current, status: 'signedIn', organization, notice: null }));
    },
    [queryClient, state.organizations],
  );

  const value = useMemo<SessionContextValue>(
    () => ({ ...state, signIn, signOut, selectOrganization, retryRestore: () => void restore() }),
    [state, signIn, signOut, selectOrganization, restore],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionContextValue {
  const context = useContext(SessionContext);

  if (!context) {
    throw new Error('useSession must be used inside <SessionProvider>');
  }

  return context;
}

/** For screens under the `(app)` group, where the guard guarantees an active organization. */
export function useActiveOrganization(): Organization {
  const { organization } = useSession();

  if (!organization) {
    throw new Error('useActiveOrganization requires a signed-in session');
  }

  return organization;
}
