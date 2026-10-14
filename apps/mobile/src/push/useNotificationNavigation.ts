import * as Notifications from 'expo-notifications';
import { useRouter, type Href } from 'expo-router';
import { useEffect, useRef } from 'react';

import { useSession } from '@/session/SessionProvider';

/** The only screens a notification may open, whatever its `data.url` says. */
const SCREENS: Record<string, Href> = {
  '/': '/',
  '/transactions': '/transactions',
};

/**
 * Tapping an alert opens its screen, in the organization it belongs to. Also covers the tap that
 * launched the app: the last response is read once the session is signed in.
 */
export function useNotificationNavigation(): void {
  const response = Notifications.useLastNotificationResponse();
  const { organization, organizations, selectOrganization } = useSession();
  const router = useRouter();
  const handled = useRef<string | null>(null);

  useEffect(() => {
    if (!response || response.actionIdentifier !== Notifications.DEFAULT_ACTION_IDENTIFIER) {
      return;
    }

    const identifier = response.notification.request.identifier;

    if (handled.current === identifier) {
      return;
    }

    handled.current = identifier;
    Notifications.clearLastNotificationResponse();

    const data = response.notification.request.content.data ?? {};
    const organizationId = typeof data.organization_id === 'string' ? data.organization_id : null;
    const screen = (typeof data.url === 'string' ? SCREENS[data.url] : undefined) ?? SCREENS['/']!;

    void (async () => {
      if (organizationId && organizationId !== organization?.id && organizations.some((candidate) => candidate.id === organizationId)) {
        await selectOrganization(organizationId);
      }

      router.navigate(screen);
    })();
  }, [response, organization?.id, organizations, selectOrganization, router]);
}
