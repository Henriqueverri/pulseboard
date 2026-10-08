import { StyleSheet, View } from 'react-native';

import { Text } from './Text';
import { colors, radius, spacing } from './theme';

const TONES = {
  info: { backgroundColor: colors.primarySoft, color: colors.primary },
  warning: { backgroundColor: colors.warningSoft, color: colors.warning },
  error: { backgroundColor: colors.negativeSoft, color: colors.negative },
} as const;

/** Inline message announced to screen readers (expired session, form errors). */
export function Notice({ message, tone = 'info' }: { message: string; tone?: keyof typeof TONES }) {
  return (
    <View style={[styles.container, { backgroundColor: TONES[tone].backgroundColor }]} accessibilityRole="alert" accessibilityLiveRegion="polite">
      <Text variant="caption" style={{ color: TONES[tone].color }}>
        {message}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    padding: spacing.md,
    borderRadius: radius.md,
  },
});
