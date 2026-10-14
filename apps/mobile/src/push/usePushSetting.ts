import { useCallback, useEffect, useState } from 'react';

import {
  currentPushStatus,
  isPushEnabled,
  registerForPush,
  setPushEnabled,
  unregisterFromPush,
  type PushStatus,
} from './pushRegistration';

export interface PushSetting {
  /** null while SecureStore is read. */
  enabled: boolean | null;
  status: PushStatus | null;
  pending: boolean;
  setEnabled: (enabled: boolean) => Promise<void>;
}

/** The on/off switch of the alerts on this device (account screen). */
export function usePushSetting(): PushSetting {
  const [enabled, setEnabledState] = useState<boolean | null>(null);
  const [status, setStatus] = useState<PushStatus | null>(null);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    let active = true;

    void (async () => {
      const stored = await isPushEnabled();
      const initialStatus = stored ? await currentPushStatus() : null;

      if (active) {
        setEnabledState(stored);
        setStatus(initialStatus);
      }
    })();

    return () => {
      active = false;
    };
  }, []);

  const setEnabled = useCallback(async (next: boolean) => {
    setPending(true);
    setStatus(null);

    try {
      if (next) {
        const result = await registerForPush();
        setStatus(result === 'registered' ? null : result);

        if (result === 'registered') {
          await setPushEnabled(true);
          setEnabledState(true);
        }
      } else if (await unregisterFromPush()) {
        await setPushEnabled(false);
        setEnabledState(false);
      } else {
        setStatus('failed');
      }
    } finally {
      setPending(false);
    }
  }, []);

  return { enabled, status, pending, setEnabled };
}
