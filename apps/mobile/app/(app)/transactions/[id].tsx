import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { RefreshControl, StyleSheet, View } from 'react-native';

import { isApiError } from '@/api/errors';
import type { TransactionDetail } from '@/api/types';
import { SOURCE_LABELS } from '@/features/transactions/labels';
import { StatusBadge } from '@/features/transactions/StatusBadge';
import { StatusTimeline } from '@/features/transactions/StatusTimeline';
import { useTransaction } from '@/features/transactions/useTransactions';
import { formatDateTime } from '@/lib/dates';
import { formatMoney } from '@/lib/money';
import { useActiveOrganization } from '@/session/SessionProvider';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorState } from '@/ui/ErrorState';
import { LoadingState } from '@/ui/LoadingState';
import { Screen } from '@/ui/Screen';
import { Text } from '@/ui/Text';
import { colors, spacing } from '@/ui/theme';

function backToList() {
  if (router.canGoBack()) {
    router.back();
  } else {
    router.replace('/transactions');
  }
}

export default function TransactionScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const organization = useActiveOrganization();
  const query = useTransaction(organization.id, id);
  const [refreshing, setRefreshing] = useState(false);

  async function onRefresh() {
    setRefreshing(true);
    await query.refetch();
    setRefreshing(false);
  }

  if (query.isPending) {
    return (
      <Screen edges={['left', 'right']}>
        <LoadingState label="Carregando transação…" />
      </Screen>
    );
  }

  if (query.isError) {
    // Unknown id or a transaction of another organization: the API answers 404 for both.
    const notFound = isApiError(query.error) && query.error.isNotFound;

    return (
      <Screen edges={['left', 'right']}>
        {notFound ? (
          <EmptyState title="Transação não encontrada" description="Ela não existe ou não pertence a esta organização." />
        ) : (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} retrying={query.isFetching} />
        )}
        <Button label="Voltar para transações" variant="ghost" onPress={backToList} />
      </Screen>
    );
  }

  return (
    <Screen
      scroll
      edges={['left', 'right']}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.primary} />}
    >
      <TransactionContent
        transaction={query.data}
        currency={organization.currency}
        timezone={organization.timezone}
      />
    </Screen>
  );
}

function TransactionContent({
  transaction,
  currency,
  timezone,
}: {
  transaction: TransactionDetail;
  currency: string;
  timezone: string;
}) {
  const { customer } = transaction;

  return (
    <>
      <View style={styles.summary}>
        <Text variant="title" accessibilityRole="header">
          {formatMoney(transaction.total_amount, currency)}
        </Text>
        <StatusBadge status={transaction.status} />
        <Text tone="muted">{formatDateTime(transaction.occurred_at, timezone)}</Text>
      </View>

      <Card>
        <Text variant="caption" tone="muted">
          Cliente
        </Text>
        <Text variant="label">
          {customer.name}
          {customer.is_deleted ? ' (removido)' : ''}
        </Text>
        <Text tone="muted" selectable>
          {customer.email}
        </Text>
      </Card>

      <Card style={styles.list}>
        <Text variant="heading" accessibilityRole="header">
          Itens
        </Text>
        {transaction.items.length === 0 ? (
          <Text tone="muted">Nenhum item registrado.</Text>
        ) : (
          transaction.items.map((item) => (
            <View key={item.id} style={styles.item} accessible>
              <View style={styles.itemMain}>
                <Text variant="label">
                  {item.product.name}
                  {item.product.is_deleted ? ' (removido)' : ''}
                </Text>
                <Text variant="caption" tone="muted">
                  {item.quantity} × {formatMoney(item.unit_price, currency)}
                </Text>
              </View>
              <Text variant="label">{formatMoney(item.line_total, currency)}</Text>
            </View>
          ))
        )}
      </Card>

      <Card style={styles.list}>
        <Text variant="heading" accessibilityRole="header">
          Linha do tempo
        </Text>
        <StatusTimeline history={transaction.status_history} timezone={timezone} />
      </Card>

      <Card>
        <Text variant="heading" accessibilityRole="header">
          Identificação
        </Text>
        <Field label="Origem" value={SOURCE_LABELS[transaction.source]} />
        {transaction.external_id ? <Field label="ID externo" value={transaction.external_id} /> : null}
        <Field label="ID" value={transaction.id} />
      </Card>
    </>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.field}>
      <Text variant="caption" tone="muted">
        {label}
      </Text>
      <Text selectable>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  summary: {
    gap: spacing.xs,
  },
  list: {
    gap: spacing.md,
  },
  item: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  itemMain: {
    flex: 1,
    gap: 2,
  },
  field: {
    marginTop: spacing.sm,
    gap: 2,
  },
});
