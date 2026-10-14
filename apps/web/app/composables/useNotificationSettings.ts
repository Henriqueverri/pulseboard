import { useOrganizationRepository } from '~/repositories/organizationRepository'

/** The daily revenue goal (owner only); the session organization follows the API answer. */
export function useNotificationSettings() {
  const repository = useOrganizationRepository()
  const auth = useAuthStore()
  const pending = ref(false)

  async function updateGoal(dailyRevenueGoal: string | null) {
    pending.value = true

    try {
      auth.updateOrganization(await repository.updateNotifications(dailyRevenueGoal))
    }
    finally {
      pending.value = false
    }
  }

  return { updateGoal, pending }
}
