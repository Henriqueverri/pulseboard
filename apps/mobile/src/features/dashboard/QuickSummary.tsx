import { StyleSheet } from 'react-native';

import type { DashboardMetrics } from '@/api/types';
import { quickSummary } from '@/lib/comparison';
import { Card } from '@/ui/Card';
import { Text } from '@/ui/Text';
import { colors } from '@/ui/theme';

interface QuickSummaryProps {
  metrics: DashboardMetrics;
  currency: string;
  periodPhrase: string;
}

export function QuickSummary({ metrics, currency, periodPhrase }: QuickSummaryProps) {
  return (
    <Card style={styles.card}>
      <Text variant="caption" tone="primary" style={styles.title}>
        Resumo
      </Text>
      <Text variant="heading">{quickSummary(metrics, currency, periodPhrase)}</Text>
    </Card>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.primarySoft,
    borderColor: colors.primarySoft,
  },
  title: {
    fontWeight: '600',
  },
});
