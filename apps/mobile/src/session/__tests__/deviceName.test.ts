import * as SecureStore from 'expo-secure-store';

import { getDeviceName } from '../deviceName';

jest.mock('expo-device', () => ({ modelName: 'Pixel 8', deviceName: null }));

describe('getDeviceName', () => {
  it('appends a random suffix persisted per installation', async () => {
    const first = await getDeviceName();
    const second = await getDeviceName();

    expect(first).toMatch(/^Pixel 8 · [0-9a-f]{4}$/);
    expect(second).toBe(first);
    expect(await SecureStore.getItemAsync('pulseboard.device_suffix')).toBe(first.slice(-4));
  });

  it('reuses an existing suffix', async () => {
    await SecureStore.setItemAsync('pulseboard.device_suffix', 'a1b2');

    await expect(getDeviceName()).resolves.toBe('Pixel 8 · a1b2');
  });
});
