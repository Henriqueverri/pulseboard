import { defineStore } from 'pinia'
import { useAuthRepository } from '~/repositories/authRepository'
import type { LoginInput, Organization, RegisterInput, User } from '~/types/auth'
import { ApiError } from '~/utils/api-error'
import { readOrganizationCookie, writeOrganizationCookie } from '~/utils/organization-cookie'

interface AuthState {
  user: User | null
  organization: Organization | null
  organizations: Organization[]
  /** `true` once the session state is known (loaded, or confirmed absent by a 401). */
  bootstrapped: boolean
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    user: null,
    organization: null,
    organizations: [],
    bootstrapped: false,
  }),

  getters: {
    isAuthenticated: (state): boolean => state.user !== null,
  },

  actions: {
    setSession(user: User, organization: Organization | null, organizations: Organization[] = []) {
      this.user = user
      this.organization = organization
      this.organizations = organizations.length > 0
        ? organizations
        : (organization ? [organization] : [])
      this.bootstrapped = true
      writeOrganizationCookie(organization?.id ?? null)
    },

    /** Local only; the remembered organization survives so the next login reopens it. */
    clearSession() {
      this.user = null
      this.organization = null
      this.organizations = []
    },

    /**
     * Loads the session with the remembered organization. The API falls back to the first
     * membership when that organization is unknown or no longer accessible.
     */
    async bootstrap() {
      const { me } = useAuthRepository()

      try {
        const payload = await me(this.organization?.id ?? readOrganizationCookie())
        this.setSession(payload.user, payload.current_organization, payload.organizations)
      }
      catch (error) {
        this.clearSession()
        if (!(error instanceof ApiError && error.status === 401)) {
          throw error
        }
        this.bootstrapped = true
      }
    },

    selectOrganization(organizationId: string) {
      const organization = this.organizations.find(candidate => candidate.id === organizationId)

      if (!organization) {
        throw new Error(`Organization ${organizationId} is not in the session.`)
      }

      this.organization = organization
      writeOrganizationCookie(organization.id)
    },

    /** Applies an organization returned by a mutation (e.g. the insights opt-in) to the session. */
    updateOrganization(organization: Organization) {
      const merge = (current: Organization) => ({ ...current, ...organization })

      this.organizations = this.organizations.map(item => (item.id === organization.id ? merge(item) : item))
      if (this.organization?.id === organization.id) {
        this.organization = merge(this.organization)
      }
    },

    /** The selected organization was rejected (membership removed): fall back to the API default. */
    async recoverOrganization() {
      this.organization = null
      writeOrganizationCookie(null)
      await this.bootstrap()
    },

    async register(input: RegisterInput) {
      const { register } = useAuthRepository()
      const payload = await register(input)
      this.setSession(payload.user, payload.organization)
      await this.bootstrap()
    },

    async login(input: LoginInput) {
      const { login } = useAuthRepository()
      const payload = await login(input)
      this.user = payload.user
      await this.bootstrap()
    },

    async logout() {
      const { logout } = useAuthRepository()

      try {
        await logout()
      }
      finally {
        this.clearSession()
        this.bootstrapped = true
      }
    },
  },
})
