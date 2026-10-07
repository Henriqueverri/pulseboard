import { Tabs } from 'expo-router/tabs';

import { colors, typography } from '@/ui/theme';

export default function AppLayout() {
  return (
    <Tabs
      screenOptions={{
        headerStyle: { backgroundColor: colors.surface },
        headerTitleStyle: { ...typography.heading, color: colors.text },
        sceneStyle: { backgroundColor: colors.background },
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.textMuted,
        // Text-only tabs: no icon library just for three tabs.
        tabBarIconStyle: { display: 'none' },
        tabBarLabelStyle: { ...typography.label },
        tabBarLabelPosition: 'beside-icon',
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Início' }} />
      <Tabs.Screen name="account" options={{ title: 'Conta' }} />
    </Tabs>
  );
}
