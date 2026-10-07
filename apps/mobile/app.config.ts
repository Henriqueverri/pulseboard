import type { ConfigContext, ExpoConfig } from 'expo/config';

/** Same values as `colors.primary` / `colors.background` in `src/ui/theme.ts`. */
const BRAND = '#4F46E5';
const BACKGROUND = '#F6F7FB';

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: 'PulseBoard',
  slug: 'pulseboard-mobile',
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
  },
  plugins: [
    'expo-router',
    'expo-secure-store',
    ['expo-splash-screen', { image: './assets/splash-icon.png', imageWidth: 160, backgroundColor: BACKGROUND }],
  ],
  experiments: {
    typedRoutes: true,
  },
});
