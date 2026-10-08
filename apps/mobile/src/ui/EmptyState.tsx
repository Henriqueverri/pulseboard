import { StyleSheet } from 'react-native';

import { Card } from './Card';
import { Text } from './Text';
import { spacing } from './theme';

export function EmptyState({ title, description }: { title: string; description?: string }) {
  return (
    <Card style={styles.container}>
      <Text variant="label">{title}</Text>
      {description ? (
        <Text variant="caption" tone="muted" style={styles.center}>
          {description}
        </Text>
      ) : null}
    </Card>
  );
}

const styles = StyleSheet.create({
  container: {
    alignItems: 'center',
    paddingVertical: spacing.xl,
  },
  center: {
    textAlign: 'center',
  },
});
