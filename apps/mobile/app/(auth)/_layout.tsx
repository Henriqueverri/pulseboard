import { Stack } from 'expo-router';

import { useSession } from '@/session/SessionProvider';
import { colors } from '@/ui/theme';

export default function AuthLayout() {
  const { status } = useSession();

  return (
    <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.background } }}>
      <Stack.Protected guard={status !== 'selectingOrganization'}>
        <Stack.Screen name="login" />
      </Stack.Protected>
      <Stack.Protected guard={status === 'selectingOrganization'}>
        <Stack.Screen name="select-organization" />
      </Stack.Protected>
    </Stack>
  );
}
