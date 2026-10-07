import { Stack } from 'expo-router';

import { colors, typography } from '@/ui/theme';

export default function TransactionsLayout() {
  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: colors.surface },
        headerTitleStyle: { ...typography.heading, color: colors.text },
        headerTintColor: colors.primary,
        contentStyle: { backgroundColor: colors.background },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Transações' }} />
      <Stack.Screen name="[id]" options={{ title: 'Transação', headerBackTitle: 'Voltar' }} />
    </Stack>
  );
}
