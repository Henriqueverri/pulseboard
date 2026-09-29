import { HOME_PATH } from '~/utils/auth-redirect'

export function useOrganization() {
  const store = useAuthStore()

  const organization = computed(() => store.organization)
  const organizations = computed(() => store.organizations)

  /** Switching drops every cached response of the previous organization and restarts at the dashboard. */
  async function switchOrganization(organizationId: string) {
    if (organizationId === store.organization?.id) {
      return
    }

    store.selectOrganization(organizationId)
    clearNuxtData()
    await navigateTo(HOME_PATH)
  }

  return {
    organization,
    organizations,
    hasMultipleOrganizations: computed(() => store.organizations.length > 1),
    currency: computed(() => store.organization?.currency ?? 'BRL'),
    timezone: computed(() => store.organization?.timezone ?? 'UTC'),
    switchOrganization,
  }
}
