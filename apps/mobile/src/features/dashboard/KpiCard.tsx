import { StyleSheet, View } from 'react-native';

import type { Comparison } from '@/api/types';
import { describeChange } from '@/lib/comparison';
import { Card } from '@/ui/Card';
import { Text } from '@/ui/Text';
import { colors, radius, spacing } from '@/ui/theme';

interface KpiCardProps {
  label: string;
  metric: Comparison<unknown>;
  /** Current and previous values, already formatted. */
  value: string;
  previous: string;
}

const ARROWS = { up: '▲', down: '▼', flat: '■', none: '' } as const;

const TONE_STYLES = {
  positive: { color: colors.positive, backgroundColor: colors.positiveSoft },
  negative: { color: colors.negative, backgroundColor: colors.negativeSoft },
  neutral: { color: colors.textMuted, backgroundColor: colors.neutralSoft },
} as const;

export function KpiCard({ label, metric, value, previous }: KpiCardProps) {
  const change = describeChange(metric);
  const tone = TONE_STYLES[change.tone];

  return (
    <Card accessible accessibilityLabel={`${label}: ${value}. ${change.label} Anterior: ${previous}.`}>
      <Text variant="caption" tone="muted">
        {label}
      </Text>
      <Text variant="title">{value}</Text>
      <View style={styles.footer}>
        <View style={[styles.badge, { backgroundColor: tone.backgroundColor }]}>
          <Text variant="caption" style={[styles.badgeText, { color: tone.color }]}>
            {[ARROWS[change.trend], change.text].filter(Boolean).join(' ')}
          </Text>
        </View>
        <Text variant="caption" tone="muted" style={styles.previous} numberOfLines={1}>
          antes {previous}
        </Text>
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  footer: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
  },
  badge: {
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    borderRadius: radius.pill,
  },
  badgeText: {
    fontWeight: '600',
  },
  previous: {
    flexShrink: 1,
  },
});
