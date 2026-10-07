import { StyleSheet, View } from 'react-native';

import type { TransactionStatus } from '@/api/types';
import { Text } from '@/ui/Text';
import { colors, radius, spacing } from '@/ui/theme';

import { STATUS_LABELS } from './labels';

const TONES: Record<TransactionStatus, { color: string; backgroundColor: string }> = {
  paid: { color: colors.positive, backgroundColor: colors.positiveSoft },
  pending: { color: colors.warning, backgroundColor: colors.warningSoft },
  refunded: { color: colors.primary, backgroundColor: colors.primarySoft },
  canceled: { color: colors.negative, backgroundColor: colors.negativeSoft },
};

export function StatusBadge({ status }: { status: TransactionStatus }) {
  const tone = TONES[status];

  return (
    <View style={[styles.badge, { backgroundColor: tone.backgroundColor }]}>
      <Text variant="caption" style={[styles.label, { color: tone.color }]}>
        {STATUS_LABELS[status]}
      </Text>
    </View>
  );
}

/** Neutral tag, e.g. the "Integração" origin. */
export function Tag({ label }: { label: string }) {
  return (
    <View style={[styles.badge, styles.neutral]}>
      <Text variant="caption" tone="muted" style={styles.label}>
        {label}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    alignSelf: 'flex-start',
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    borderRadius: radius.pill,
  },
  neutral: {
    backgroundColor: colors.neutralSoft,
  },
  label: {
    fontWeight: '600',
  },
});
