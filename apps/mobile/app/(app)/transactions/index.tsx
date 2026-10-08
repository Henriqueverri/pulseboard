import { router } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, View } from 'react-native';

import type { Transaction, TransactionStatus } from '@/api/types';
import { TransactionFilters } from '@/features/transactions/TransactionFilters';
import { TransactionRow } from '@/features/transactions/TransactionRow';
import { useTransactions } from '@/features/transactions/useTransactions';
import { DEFAULT_PRESET, presetRange, type PeriodPreset } from '@/lib/period';
import { useActiveOrganization } from '@/session/SessionProvider';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorState } from '@/ui/ErrorState';
import { LoadingState } from '@/ui/LoadingState';
import { Text } from '@/ui/Text';
import { colors, spacing } from '@/ui/theme';

function countLabel(total: number): string {
  return total === 1 ? '1 transação' : `${total.toLocaleString('pt-BR')} transações`;
}

export default function TransactionsScreen() {
  const organization = useActiveOrganization();
  const [status, setStatus] = useState<TransactionStatus | null>(null);
  const [preset, setPreset] = useState<PeriodPreset>(DEFAULT_PRESET);
  const [search, setSearch] = useState('');
  const [refreshing, setRefreshing] = useState(false);
  const range = presetRange(preset, organization.timezone);
  const query = useTransactions(organization.id, { status, range, search });

  const transactions = query.data?.pages.flatMap((page) => page.data) ?? [];
  const total = query.data?.pages[0]?.meta.total;
  const filtered = status !== null || search !== '';

  const openTransaction = useCallback((id: string) => {
    router.push({ pathname: '/transactions/[id]', params: { id } });
  }, []);

  const renderItem = useCallback(
    ({ item }: { item: Transaction }) => (
      <TransactionRow
        transaction={item}
        currency={organization.currency}
        timezone={organization.timezone}
        onPress={openTransaction}
      />
    ),
    [organization.currency, organization.timezone, openTransaction],
  );

  async function onRefresh() {
    setRefreshing(true);
    await query.refetch();
    setRefreshing(false);
  }

  function onEndReached() {
    // A failed page is retried from the footer button, not by every scroll event.
    if (query.hasNextPage && !query.isFetchingNextPage && !query.isFetchNextPageError) {
      void query.fetchNextPage();
    }
  }

  function renderEmpty() {
    if (query.isPending) {
      return <LoadingState label="Carregando transações…" />;
    }

    if (query.isError) {
      return <ErrorState error={query.error} onRetry={() => void query.refetch()} retrying={query.isFetching} />;
    }

    return filtered ? (
      <EmptyState title="Nenhuma transação encontrada" description="Ajuste a busca, o status ou o período." />
    ) : (
      <EmptyState title="Nenhuma transação no período" description="As vendas aparecem aqui assim que forem registradas." />
    );
  }

  function renderFooter() {
    if (query.isFetchingNextPage) {
      return <ActivityIndicator color={colors.primary} style={styles.footer} accessibilityLabel="Carregando mais transações" />;
    }

    if (query.isFetchNextPageError) {
      return (
        <ErrorState
          title="Não foi possível carregar mais"
          error={query.error}
          onRetry={() => void query.fetchNextPage()}
        />
      );
    }

    if (transactions.length > 0 && !query.hasNextPage) {
      return (
        <Text variant="caption" tone="muted" style={[styles.footer, styles.center]}>
          Fim da lista
        </Text>
      );
    }

    return null;
  }

  return (
    <FlatList
      testID="transactions-list"
      data={transactions}
      keyExtractor={(item) => item.id}
      renderItem={renderItem}
      contentContainerStyle={styles.content}
      style={query.isPlaceholderData ? styles.stale : null}
      keyboardShouldPersistTaps="handled"
      ListHeaderComponent={
        <View style={styles.header}>
          <TransactionFilters
            status={status}
            onStatusChange={setStatus}
            preset={preset}
            onPresetChange={setPreset}
            onSearch={setSearch}
          />
          {total !== undefined ? (
            <Text variant="caption" tone="muted" accessibilityLiveRegion="polite">
              {countLabel(total)}
            </Text>
          ) : null}
        </View>
      }
      ListEmptyComponent={renderEmpty()}
      ListFooterComponent={renderFooter()}
      onEndReached={onEndReached}
      onEndReachedThreshold={0.5}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.primary} />}
    />
  );
}

const styles = StyleSheet.create({
  content: {
    gap: spacing.sm,
    padding: spacing.lg,
  },
  header: {
    gap: spacing.md,
    marginBottom: spacing.sm,
  },
  stale: {
    opacity: 0.6,
  },
  footer: {
    paddingVertical: spacing.lg,
  },
  center: {
    textAlign: 'center',
  },
});
