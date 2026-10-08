import { memo } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import type { Transaction } from '@/api/types';
import { formatDateTime } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { Text } from '@/ui/Text';
import { colors, radius, spacing } from '@/ui/theme';

import { SOURCE_LABELS, STATUS_LABELS } from './labels';
import { StatusBadge, Tag } from './StatusBadge';

interface TransactionRowProps {
  transaction: Transaction;
  currency: string;
  timezone: string;
  onPress: (id: string) => void;
}

export const TransactionRow = memo(function TransactionRow({ transaction, currency, timezone, onPress }: TransactionRowProps) {
  const amount = formatMoney(transaction.total_amount, currency);
  const occurredAt = formatDateTime(transaction.occurred_at, timezone);
  const ingested = transaction.source === 'ingest';
  const items = transaction.items_count === 1 ? '1 item' : `${transaction.items_count} itens`;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${transaction.customer.name}, ${amount}, ${STATUS_LABELS[transaction.status]}, ${occurredAt}${
        ingested ? `, ${SOURCE_LABELS.ingest}` : ''
      }`}
      accessibilityHint="Abre o detalhe da transação"
      onPress={() => onPress(transaction.id)}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
    >
      <View style={styles.main}>
        <Text variant="label" numberOfLines={1}>
          {transaction.customer.name}
        </Text>
        <Text variant="caption" tone="muted">
          {occurredAt} · {items}
        </Text>
        {ingested ? <Tag label={SOURCE_LABELS.ingest} /> : null}
      </View>
      <View style={styles.side}>
        <Text variant="label">{amount}</Text>
        <StatusBadge status={transaction.status} />
      </View>
    </Pressable>
  );
});

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    gap: spacing.md,
    padding: spacing.lg,
    borderRadius: radius.lg,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  pressed: {
    backgroundColor: colors.neutralSoft,
  },
  main: {
    flex: 1,
    gap: spacing.xs,
  },
  side: {
    alignItems: 'flex-end',
    gap: spacing.xs,
  },
});
