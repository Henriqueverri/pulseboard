import { defineStore } from 'pinia'
import { useAuthRepository } from '~/repositories/authRepository'
import type { Organization, User } from '~/types/auth'
import { ApiError } from '~/types/auth'

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
        const payload = await me()
        this.setSession(
          payload.user,
          payload.current_organization,
          payload.organizations,
        )
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

    async register(input: {
      name: string
      email: string
      password: string
      password_confirmation: string
    }) {
      const { register } = useAuthRepository()
      const payload = await register(input)
      this.setSession(
        payload.user,
        payload.organization,
        payload.organization ? [payload.organization] : [],
      )
    },

    async login(input: { email: string, password: string }) {
      const { login } = useAuthRepository()
      const payload = await login(input)
      this.setSession(
        payload.user,
        payload.organization,
        payload.organization ? [payload.organization] : [],
      )
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
