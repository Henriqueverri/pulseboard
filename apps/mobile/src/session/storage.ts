import * as SecureStore from 'expo-secure-store';

/** Keychain (iOS) / Keystore (Android). The token never goes to AsyncStorage. */
const KEYS = {
  token: 'pulseboard.token',
  organizationId: 'pulseboard.organization_id',
} as const;

export interface StoredSession {
  token: string;
  organizationId: string | null;
}

export async function loadStoredSession(): Promise<StoredSession | null> {
  const [token, organizationId] = await Promise.all([
    SecureStore.getItemAsync(KEYS.token),
    SecureStore.getItemAsync(KEYS.organizationId),
  ]);

  return token ? { token, organizationId } : null;
}

export async function saveStoredSession({ token, organizationId }: StoredSession): Promise<void> {
  await SecureStore.setItemAsync(KEYS.token, token);

  if (organizationId) {
    await SecureStore.setItemAsync(KEYS.organizationId, organizationId);
  } else {
    await SecureStore.deleteItemAsync(KEYS.organizationId);
  }
}

export async function clearStoredSession(): Promise<void> {
  await Promise.all([
    SecureStore.deleteItemAsync(KEYS.token),
    SecureStore.deleteItemAsync(KEYS.organizationId),
  ]);
}
