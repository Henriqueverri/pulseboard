import * as Device from 'expo-device';
import * as SecureStore from 'expo-secure-store';

const SUFFIX_KEY = 'pulseboard.device_suffix';
const MAX_LENGTH = 100;

function randomSuffix(): string {
  return Math.floor(Math.random() * 0x10000)
    .toString(16)
    .padStart(4, '0');
}

/**
 * `device_name` of the token, e.g. "Pixel 8 · a1b2". A new token replaces the previous one with
 * the same name, so the suffix (random, persisted per installation, kept on logout) stops visitors
 * sharing a demo account on the same phone model from revoking each other's tokens.
 */
export async function getDeviceName(): Promise<string> {
  let suffix = await SecureStore.getItemAsync(SUFFIX_KEY);

  if (!suffix) {
    suffix = randomSuffix();
    await SecureStore.setItemAsync(SUFFIX_KEY, suffix);
  }

  const model = (Device.modelName ?? Device.deviceName ?? 'Dispositivo').trim() || 'Dispositivo';
  const tail = ` · ${suffix}`;

  return `${model.slice(0, MAX_LENGTH - tail.length)}${tail}`;
}
