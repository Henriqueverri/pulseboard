import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { Text } from './Text';
import { colors, spacing } from './theme';

export function LoadingState({ label = 'Carregando…' }: { label?: string }) {
  return (
    <View style={styles.container} accessibilityRole="progressbar" accessibilityLabel={label}>
      <ActivityIndicator color={colors.primary} />
      <Text tone="muted">{label}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    paddingVertical: spacing.xxl,
  },
});
