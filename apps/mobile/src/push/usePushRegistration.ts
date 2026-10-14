import { useEffect } from 'react';

import { isPushEnabled, registerForPush } from './pushRegistration';

/**
 * Registers the device for alerts once per signed-in session (after login and on every app start),
 * unless the user turned them off on this device. The first time, this is the permission prompt.
 */
export function usePushRegistration(): void {
  useEffect(() => {
    void (async () => {
      if (await isPushEnabled()) {
        await registerForPush();
      }
    })();
  }, []);
}
