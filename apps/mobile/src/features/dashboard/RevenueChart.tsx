import { useState } from 'react';
import { View } from 'react-native';
import { BarChart } from 'react-native-gifted-charts';

import type { RevenueBucket } from '@/api/types';
import { formatDayMonth } from '@/lib/dates';
import { decimalToNumber, formatMoney } from '@/lib/money';
import { Card } from '@/ui/Card';
import { Text } from '@/ui/Text';
import { colors, spacing } from '@/ui/theme';

interface RevenueChartProps {
  buckets: RevenueBucket[];
  currency: string;
  granularity: 'day' | 'week' | 'month';
}

const CHART_HEIGHT = 160;

/** Labels only on the first, middle and last bars: a phone has no room for 30 dates. */
function labelIndexes(count: number): Set<number> {
  return new Set([0, Math.floor((count - 1) / 2), count - 1]);
}

/** Only this component knows the chart library (ADR-006): swapping it touches nothing else. */
export function RevenueChart({ buckets, currency, granularity }: RevenueChartProps) {
  const [width, setWidth] = useState(0);
  const labelled = labelIndexes(buckets.length);
  const values = buckets.map((bucket) => decimalToNumber(bucket.revenue) ?? 0);
  const best = buckets[values.indexOf(Math.max(...values))];

  const data = buckets.map((bucket, index) => ({
    value: values[index] ?? 0,
    label: labelled.has(index) ? formatDayMonth(bucket.from) : '',
    frontColor: colors.primary,
  }));

  const slot = width > 0 ? width / Math.max(buckets.length, 1) : 0;
  const unit = granularity === 'day' ? 'diária' : 'semanal';

  return (
    <Card
      accessible
      accessibilityLabel={
        best
          ? `Gráfico de receita ${unit}. Maior valor: ${formatMoney(best.revenue, currency)} em ${formatDayMonth(best.from)}.`
          : `Gráfico de receita ${unit}.`
      }
    >
      <Text variant="label">Receita {unit}</Text>
      {best ? (
        <Text variant="caption" tone="muted">
          Melhor {granularity === 'day' ? 'dia' : 'semana'}: {formatMoney(best.revenue, currency)} em {formatDayMonth(best.from)}
        </Text>
      ) : null}
      <View
        style={{ marginTop: spacing.sm }}
        onLayout={(event) => setWidth(event.nativeEvent.layout.width)}
        importantForAccessibility="no-hide-descendants"
      >
        {width > 0 ? (
          <BarChart
            data={data}
            height={CHART_HEIGHT}
            width={width}
            barWidth={Math.max(slot * 0.6, 2)}
            spacing={slot * 0.4}
            initialSpacing={slot * 0.2}
            endSpacing={0}
            barBorderTopLeftRadius={3}
            barBorderTopRightRadius={3}
            yAxisLabelWidth={0}
            hideYAxisText
            yAxisThickness={0}
            xAxisColor={colors.border}
            rulesColor={colors.border}
            noOfSections={3}
            xAxisLabelTextStyle={{ color: colors.textMuted, fontSize: 11, width: 40, marginLeft: -12 }}
            disableScroll
            disablePress
          />
        ) : null}
      </View>
    </Card>
  );
}
