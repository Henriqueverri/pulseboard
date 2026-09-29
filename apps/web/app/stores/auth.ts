import { defineStore } from 'pinia'
import { useAuthRepository } from '~/repositories/authRepository'
import type { LoginInput, Organization, RegisterInput, User } from '~/types/auth'
import { ApiError } from '~/utils/api-error'

interface AuthState {
  user: User | null
  organization: Organization | null
  organizations: Organization[]
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
    },

    clearSession() {
      this.user = null
      this.organization = null
      this.organizations = []
    },

    async bootstrap() {
      const { me } = useAuthRepository()

      try {
        const payload = await me(this.organization?.id ?? null)
        this.setSession(payload.user, payload.current_organization, payload.organizations)
      }
      catch (error) {
        this.clearSession()
        if (!(error instanceof ApiError && error.status === 401)) {
          throw error
        }
      }
      finally {
        this.bootstrapped = true
      }
    },

    /** The selected organization was rejected (membership removed): fall back to the API default. */
    async recoverOrganization() {
      this.organization = null
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
      this.setSession(payload.user, payload.organization)
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
