import Constants, { ExecutionEnvironment } from 'expo-constants';
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

import { registerPushToken, unregisterPushToken } from '@/api/push';

/** Same id the API sends in `channelId`: without the channel, Android drops the notification. */
export const ANDROID_CHANNEL_ID = 'alerts';

const KEYS = {
  /** Per installation: '1' once the user turned the alerts off on this device. */
  disabled: 'pulseboard.push_disabled',
  /** The last Expo token registered, so turning off does not need to fetch it again. */
  token: 'pulseboard.push_token',
} as const;

export type PushStatus =
  | 'registered'
  /** Permission refused; once the system stops asking, only its settings can grant it. */
  | 'denied'
  /** Expo Go on Android has no remote push since SDK 53: only a build (APK) receives them. */
  | 'unsupported'
  /** Offline, Expo or the API unreachable: trying again later may work. */
  | 'failed';

export async function isPushEnabled(): Promise<boolean> {
  return (await SecureStore.getItemAsync(KEYS.disabled)) !== '1';
}

export async function setPushEnabled(enabled: boolean): Promise<void> {
  if (enabled) {
    await SecureStore.deleteItemAsync(KEYS.disabled);
  } else {
    await SecureStore.setItemAsync(KEYS.disabled, '1');
  }
}

function isExpoGoOnAndroid(): boolean {
  return Platform.OS === 'android' && Constants.executionEnvironment === ExecutionEnvironment.StoreClient;
}

/** What the account screen shows before the user touches the switch. */
export async function currentPushStatus(): Promise<PushStatus | null> {
  if (isExpoGoOnAndroid()) {
    return 'unsupported';
  }

  const permission = await Notifications.getPermissionsAsync();

  return !permission.granted && !permission.canAskAgain ? 'denied' : null;
}

/**
 * Asks for permission (only while the system still allows asking), gets the Expo push token and
 * registers it with the API. Safe to call on every app start: registering again is idempotent.
 */
export async function registerForPush(): Promise<PushStatus> {
  if (isExpoGoOnAndroid()) {
    return 'unsupported';
  }

  try {
    if (Platform.OS === 'android') {
      // Android 13+ only shows the permission prompt once a channel exists.
      await Notifications.setNotificationChannelAsync(ANDROID_CHANNEL_ID, {
        name: 'Alertas',
        importance: Notifications.AndroidImportance.HIGH,
      });
    }

    let permission = await Notifications.getPermissionsAsync();

    if (!permission.granted && permission.canAskAgain) {
      permission = await Notifications.requestPermissionsAsync();
    }

    if (!permission.granted) {
      return 'denied';
    }

    const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;
    const { data: token } = await Notifications.getExpoPushTokenAsync({ projectId });

    await registerPushToken(token, Platform.OS === 'ios' ? 'ios' : 'android');
    await SecureStore.setItemAsync(KEYS.token, token);

    return 'registered';
  } catch {
    return 'failed';
  }
}

/** Removes this device from the API; `false` when the API could not be reached. */
export async function unregisterFromPush(): Promise<boolean> {
  const token = await SecureStore.getItemAsync(KEYS.token);

  if (!token) {
    return true;
  }

  try {
    await unregisterPushToken(token);
  } catch {
    return false;
  }

  await SecureStore.deleteItemAsync(KEYS.token);

  return true;
}
