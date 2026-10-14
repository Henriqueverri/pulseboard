import { existsSync } from 'node:fs';

import type { ConfigContext, ExpoConfig } from 'expo/config';

/** Same values as `colors.primary` / `colors.background` in `src/ui/theme.ts`. */
const BRAND = '#4F46E5';
const BACKGROUND = '#F6F7FB';
const GOOGLE_SERVICES_FILE = './google-services.json';

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: 'PulseBoard',
  slug: 'pulseboard-mobile',
  owner: 'henriqueverri',
  scheme: 'pulseboard',
  version: '1.0.0',
  orientation: 'portrait',
  icon: './assets/icon.png',
  userInterfaceStyle: 'light',
  ios: {
    supportsTablet: false,
    bundleIdentifier: 'dev.henriqueverri.pulseboard',
  },
  android: {
    package: 'dev.henriqueverri.pulseboard',
    adaptiveIcon: {
      backgroundColor: BRAND,
      foregroundImage: './assets/android-icon-foreground.png',
      backgroundImage: './assets/android-icon-background.png',
      monochromeImage: './assets/android-icon-monochrome.png',
    },
    predictiveBackGestureEnabled: false,
    // Downloaded from Firebase (public identifiers): without it the APK gets no push token.
    ...(existsSync(GOOGLE_SERVICES_FILE) ? { googleServicesFile: GOOGLE_SERVICES_FILE } : {}),
  },
  plugins: [
    'expo-router',
    'expo-secure-store',
    // The app creates the `alerts` channel before asking for permission; the API sends to it.
    ['expo-notifications', { icon: './assets/notification-icon.png', color: BRAND, defaultChannel: 'alerts' }],
    ['expo-splash-screen', { image: './assets/splash-icon.png', imageWidth: 160, backgroundColor: BACKGROUND }],
  ],
  experiments: {
    typedRoutes: true,
  },
  extra: {
    ...config.extra,
    eas: { projectId: '8ece2b27-c184-4d43-aa8d-34cf247da924' },
  },
});
