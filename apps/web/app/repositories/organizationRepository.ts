import type { Organization } from '~/types/auth'

/** Organization settings; updating requires the owner role (403). */
export function useOrganizationRepository() {
  const { apiFetch } = useApiClient()

  return {
    updateNotifications: async (dailyRevenueGoal: string | null) =>
      (await apiFetch<{ organization: Organization }>('/organization/notifications', {
        method: 'PUT',
        body: { daily_revenue_goal: dailyRevenueGoal },
      })).organization,
  }
}
