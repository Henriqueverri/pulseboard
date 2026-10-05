import type { PeriodParams } from '~/types/api'
import type { Organization } from '~/types/auth'
import type { PeriodSummaryResponse } from '~/types/insights'

/**
 * `periodSummary` never calls the AI provider (cached summary or `data: null`); `generate`
 * may, within the organization's quota. Updating the opt-in requires the owner role (403).
 */
export function useInsightsRepository() {
  const { apiFetch } = useApiClient()

  return {
    periodSummary: (params: PeriodParams) =>
      apiFetch<PeriodSummaryResponse>('/insights/period-summary', { query: { ...params } }),

    generatePeriodSummary: (params: PeriodParams) =>
      apiFetch<PeriodSummaryResponse>('/insights/period-summary', { method: 'POST', body: { ...params } }),

    updateOptIn: async (enabled: boolean) =>
      (await apiFetch<{ organization: Organization }>('/organization/insights', { method: 'PUT', body: { enabled } })).organization,
  }
}
