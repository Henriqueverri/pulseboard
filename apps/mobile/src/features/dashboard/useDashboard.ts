import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { getRevenue } from '@/api/analytics';
import { getDashboard } from '@/api/dashboard';
import { granularityFor, presetRange, type PeriodPreset } from '@/lib/period';

/**
 * KPIs and the revenue series, fetched in parallel. Keys always carry the organization, so
 * switching organizations can never show cached numbers of another one.
 */
export function useDashboard(organizationId: string, timezone: string, preset: PeriodPreset) {
  const range = presetRange(preset, timezone);
  const granularity = granularityFor(range);
  // A single bucket says nothing a KPI does not: "today" has no chart.
  const withChart = preset !== 'today';

  const dashboard = useQuery({
    queryKey: ['org', organizationId, 'dashboard', range.from, range.to],
    queryFn: ({ signal }) => getDashboard(range, signal),
    placeholderData: keepPreviousData,
  });

  const revenue = useQuery({
    queryKey: ['org', organizationId, 'revenue', range.from, range.to, granularity],
    queryFn: ({ signal }) => getRevenue(range, granularity, signal),
    placeholderData: keepPreviousData,
    enabled: withChart,
  });

  async function refresh() {
    await Promise.all([dashboard.refetch(), withChart ? revenue.refetch() : null]);
  }

  return { range, withChart, dashboard, revenue, refresh };
}
