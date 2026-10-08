import { QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { createQueryClient, refetchOnAppForeground } from '@/api/queryClient';
import { SessionProvider, useSession } from '@/session/SessionProvider';
import { Button } from '@/ui/Button';
import { ErrorState } from '@/ui/ErrorState';
import { LoadingState } from '@/ui/LoadingState';
import { Screen } from '@/ui/Screen';
import { colors } from '@/ui/theme';

export default function RootLayout() {
  const [queryClient] = useState(createQueryClient);

  useEffect(refetchOnAppForeground, []);

  return (
    <SafeAreaProvider>
      <QueryClientProvider client={queryClient}>
        <SessionProvider>
          <StatusBar style="dark" />
          <RootNavigator />
        </SessionProvider>
      </QueryClientProvider>
    </SafeAreaProvider>
  );
}

/** Route protection lives here: each group is only reachable in the matching session state. */
function RootNavigator() {
  const session = useSession();
  const signedIn = session.status === 'signedIn';

  return (
    <>
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.background } }}>
        <Stack.Protected guard={signedIn}>
          <Stack.Screen name="(app)" />
        </Stack.Protected>
        <Stack.Protected guard={!signedIn}>
          <Stack.Screen name="(auth)" />
        </Stack.Protected>
      </Stack>

      {session.status === 'restoring' || session.status === 'unavailable' ? (
        <View style={[StyleSheet.absoluteFill, styles.overlay]}>
          <Screen>
            {session.status === 'restoring' ? (
              <LoadingState label="Abrindo sua sessão…" />
            ) : (
              <>
                <ErrorState
                  title="Não foi possível validar sua sessão"
                  error={session.restoreError}
                  onRetry={session.retryRestore}
                />
                <Button label="Sair da conta" variant="ghost" onPress={() => void session.signOut()} />
              </>
            )}
          </Screen>
        </View>
      ) : null}
    </>
  );
}

const styles = StyleSheet.create({
  overlay: {
    backgroundColor: colors.background,
    justifyContent: 'center',
  },
});
