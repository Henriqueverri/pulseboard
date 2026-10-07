import * as SecureStore from 'expo-secure-store';

import { clearStoredSession, loadStoredSession, saveStoredSession } from '../storage';

describe('session storage', () => {
  it('round-trips the token and organization through SecureStore', async () => {
    await saveStoredSession({ token: '1|pbm_abc', organizationId: 'org-1' });

    expect(SecureStore.setItemAsync).toHaveBeenCalledWith('pulseboard.token', '1|pbm_abc');
    await expect(loadStoredSession()).resolves.toEqual({ token: '1|pbm_abc', organizationId: 'org-1' });
  });

  it('returns null without a token and after clearing', async () => {
    await expect(loadStoredSession()).resolves.toBeNull();

    await saveStoredSession({ token: '1|pbm_abc', organizationId: null });
    await clearStoredSession();

    await expect(loadStoredSession()).resolves.toBeNull();
  });
});
