import { StyleSheet, View } from 'react-native';

import { errorMessage, isApiError } from '@/api/errors';

import { Button } from './Button';
import { Text } from './Text';
import { colors, radius, spacing } from './theme';

interface ErrorStateProps {
  error: unknown;
  title?: string;
  onRetry?: () => void;
  retrying?: boolean;
}

/** Failure of a request, with the `X-Request-Id` so a report can be matched to the API logs. */
export function ErrorState({ error, title = 'Não foi possível carregar', onRetry, retrying = false }: ErrorStateProps) {
  const requestId = isApiError(error) ? error.requestId : null;

  return (
    <View style={styles.container} accessibilityRole="alert">
      <Text variant="heading">{title}</Text>
      <Text tone="muted">{errorMessage(error)}</Text>
      {requestId ? (
        <Text variant="caption" tone="muted" selectable>
          ID da requisição: {requestId}
        </Text>
      ) : null}
      {onRetry ? <Button label="Tentar novamente" variant="secondary" onPress={onRetry} loading={retrying} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    gap: spacing.sm,
    padding: spacing.lg,
    borderRadius: radius.lg,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
});
