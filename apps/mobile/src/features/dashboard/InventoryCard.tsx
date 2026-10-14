import { StyleSheet, View } from 'react-native';

import type { InventoryItem, InventoryResponse, StockStatus } from '@/api/types';
import { formatDaysOfCover, formatUnits, itemsNeedingAttention } from '@/lib/inventory';
import { formatInteger } from '@/lib/money';
import { Card } from '@/ui/Card';
import { Text } from '@/ui/Text';
import { colors, radius, spacing } from '@/ui/theme';

const LIMIT = 5;

const STATUS: Record<StockStatus, { label: string; color: string; backgroundColor: string }> = {
  ok: { label: 'Em dia', color: colors.positive, backgroundColor: colors.positiveSoft },
  low: { label: 'Baixo', color: colors.warning, backgroundColor: colors.warningSoft },
  out: { label: 'Zerado', color: colors.negative, backgroundColor: colors.negativeSoft },
};

/** Products to restock, most urgent first. Callers skip it when nothing has stock control. */
export function InventoryCard({ inventory }: { inventory: InventoryResponse }) {
  const { summary, items } = inventory.data;
  const windowDays = inventory.meta.sales_window.days;
  const attention = itemsNeedingAttention(items);
  const shown = attention.slice(0, LIMIT);
  const hidden = attention.length - shown.length;

  return (
    <Card style={styles.card}>
      <Text variant="heading" accessibilityRole="header">
        Estoque
      </Text>

      {attention.length === 0 ? (
        <Text tone="muted">
          Todo o estoque acima do mínimo ({formatInteger(summary.tracked)}{' '}
          {summary.tracked === 1 ? 'produto controlado' : 'produtos controlados'}).
        </Text>
      ) : (
        <>
          <Text variant="caption" tone="muted">
            {[
              summary.out > 0 ? `${formatInteger(summary.out)} ${summary.out === 1 ? 'zerado' : 'zerados'}` : null,
              summary.low > 0 ? `${formatInteger(summary.low)} ${summary.low === 1 ? 'baixo' : 'baixos'}` : null,
            ]
              .filter(Boolean)
              .join(' · ')}{' '}
            · duração pela média dos últimos {windowDays} dias
          </Text>
          {shown.map((item) => (
            <InventoryRow key={item.product.id} item={item} windowDays={windowDays} />
          ))}
          {hidden > 0 ? (
            <Text variant="caption" tone="muted">
              E mais {formatInteger(hidden)} {hidden === 1 ? 'produto' : 'produtos'} precisando de reposição.
            </Text>
          ) : null}
        </>
      )}
    </Card>
  );
}

function InventoryRow({ item, windowDays }: { item: InventoryItem; windowDays: number }) {
  const status = STATUS[item.status];
  const cover = formatDaysOfCover(item.days_of_cover, windowDays);

  return (
    <View
      style={styles.row}
      accessible
      accessibilityLabel={`${item.product.name}: ${status.label}, ${formatUnits(item.quantity)}, mínimo ${formatInteger(item.min_quantity)}. ${cover}.`}
    >
      <View style={styles.name}>
        <Text variant="label" numberOfLines={1}>
          {item.product.name}
        </Text>
        <Text variant="caption" tone="muted" numberOfLines={1}>
          {cover}
        </Text>
      </View>
      <View style={styles.balance}>
        <Text variant="label">{formatUnits(item.quantity)}</Text>
        <Text variant="caption" tone="muted">
          mín. {formatInteger(item.min_quantity)}
        </Text>
      </View>
      <View style={[styles.badge, { backgroundColor: status.backgroundColor }]}>
        <Text variant="caption" style={[styles.badgeText, { color: status.color }]}>
          {status.label}
        </Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    gap: spacing.sm,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingTop: spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.border,
  },
  name: {
    flex: 1,
  },
  balance: {
    alignItems: 'flex-end',
  },
  badge: {
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    borderRadius: radius.pill,
  },
  badgeText: {
    fontWeight: '600',
  },
});
