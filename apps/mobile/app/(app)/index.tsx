import { useState } from 'react';
import { RefreshControl, StyleSheet, View } from 'react-native';

import { KpiCard } from '@/features/dashboard/KpiCard';
import { QuickSummary } from '@/features/dashboard/QuickSummary';
import { RevenueChart } from '@/features/dashboard/RevenueChart';
import { useDashboard } from '@/features/dashboard/useDashboard';
import { formatCivilRange } from '@/lib/dates';
import { formatInteger, formatMoney } from '@/lib/money';
import { DEFAULT_PRESET, PRESET_PHRASES, type PeriodPreset } from '@/lib/period';
import { useActiveOrganization, useSession } from '@/session/SessionProvider';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorState } from '@/ui/ErrorState';
import { LoadingState } from '@/ui/LoadingState';
import { PeriodSelector } from '@/ui/PeriodSelector';
import { Screen } from '@/ui/Screen';
import { Text } from '@/ui/Text';
import { colors, spacing } from '@/ui/theme';

export default function DashboardScreen() {
  const { user } = useSession();
  const organization = useActiveOrganization();
  const [preset, setPreset] = useState<PeriodPreset>(DEFAULT_PRESET);
  const [refreshing, setRefreshing] = useState(false);
  const { withChart, dashboard, revenue, refresh } = useDashboard(organization.id, organization.timezone, preset);

  async function onRefresh() {
    setRefreshing(true);
    await refresh();
    setRefreshing(false);
  }

  const firstName = user?.name.split(' ')[0] ?? '';
  const data = dashboard.data;
  const currency = data?.meta.currency ?? organization.currency;

  return (
    <Screen
      scroll
      edges={['left', 'right']}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.primary} />}
    >
      <View>
        <Text variant="title" accessibilityRole="header">
          Olá, {firstName}
        </Text>
        <Text tone="muted">{organization.name}</Text>
      </View>

      <PeriodSelector value={preset} onChange={setPreset} />

      {dashboard.isPending ? <LoadingState label="Carregando indicadores…" /> : null}

      {dashboard.isError && !data ? (
        <ErrorState error={dashboard.error} onRetry={() => void dashboard.refetch()} retrying={dashboard.isFetching} />
      ) : null}

      {data ? (
        <View style={[styles.section, dashboard.isPlaceholderData && styles.stale]}>
          <Text variant="caption" tone="muted">
            {formatCivilRange(data.meta.period.from, data.meta.period.to)} · comparado a{' '}
            {formatCivilRange(data.meta.previous_period.from, data.meta.previous_period.to)}
          </Text>

          <QuickSummary metrics={data.data} currency={currency} periodPhrase={PRESET_PHRASES[preset]} />

          <KpiCard
            label="Receita"
            metric={data.data.revenue}
            value={formatMoney(data.data.revenue.value, currency)}
            previous={formatMoney(data.data.revenue.previous, currency)}
          />
          <View style={styles.row}>
            <View style={styles.cell}>
              <KpiCard
                label="Vendas"
                metric={data.data.orders}
                value={formatInteger(data.data.orders.value)}
                previous={formatInteger(data.data.orders.previous)}
              />
            </View>
            <View style={styles.cell}>
              <KpiCard
                label="Ticket médio"
                metric={data.data.average_order_value}
                value={formatMoney(data.data.average_order_value.value, currency)}
                previous={formatMoney(data.data.average_order_value.previous, currency)}
              />
            </View>
          </View>

          {withChart ? (
            <RevenueSection
              revenue={revenue}
              currency={currency}
              hasSales={data.data.orders.value > 0}
            />
          ) : null}
        </View>
      ) : null}
    </Screen>
  );
}

function RevenueSection({
  revenue,
  currency,
  hasSales,
}: {
  revenue: ReturnType<typeof useDashboard>['revenue'];
  currency: string;
  hasSales: boolean;
}) {
  if (revenue.isPending) {
    return <LoadingState label="Carregando gráfico…" />;
  }

  if (revenue.isError && !revenue.data) {
    return (
      <ErrorState
        title="Não foi possível carregar o gráfico"
        error={revenue.error}
        onRetry={() => void revenue.refetch()}
        retrying={revenue.isFetching}
      />
    );
  }

  if (!revenue.data) {
    return null;
  }

  if (!hasSales) {
    return <EmptyState title="Sem vendas no período" description="O gráfico aparece quando houver vendas pagas." />;
  }

  return <RevenueChart buckets={revenue.data.data} currency={currency} granularity={revenue.data.meta.granularity} />;
}

const styles = StyleSheet.create({
  section: {
    gap: spacing.md,
  },
  stale: {
    opacity: 0.6,
  },
  row: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  cell: {
    flex: 1,
  },
});
