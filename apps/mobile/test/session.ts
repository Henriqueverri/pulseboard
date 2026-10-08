import * as SecureStore from 'expo-secure-store';

import { demoStore } from './fixtures';

export const STORED_TOKEN = '7|pbm_storedtoken';

/** Simulates an app reopened after a previous login on this device. */
export async function storeSession(token = STORED_TOKEN, organizationId: string | null = demoStore.id): Promise<void> {
  await SecureStore.setItemAsync('pulseboard.token', token);

  if (organizationId) {
    await SecureStore.setItemAsync('pulseboard.organization_id', organizationId);
  }
}

export function storedToken(): Promise<string | null> {
  return SecureStore.getItemAsync('pulseboard.token');
}

export function storedOrganizationId(): Promise<string | null> {
  return SecureStore.getItemAsync('pulseboard.organization_id');
}
