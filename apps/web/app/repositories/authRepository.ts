import type { AuthPayload, LoginInput, MePayload, RegisterInput } from '~/types/auth'

export function useAuthRepository() {
  const { apiFetch } = useApiClient()

  function register(payload: RegisterInput) {
    return apiFetch<AuthPayload>('/auth/register', {
      method: 'POST',
      body: { ...payload },
      auth: false,
      organizationId: null,
    })
  }

  function login(payload: LoginInput) {
    return apiFetch<AuthPayload>('/auth/login', {
      method: 'POST',
      body: { ...payload },
      auth: false,
      organizationId: null,
    })
  }

  function logout() {
    return apiFetch<{ message: string }>('/auth/logout', {
      method: 'POST',
      organizationId: null,
      auth: false,
    })
  }

  /** `organizationId` selects `current_organization` (the API falls back to the first membership). */
  function me(organizationId: string | null = null) {
    return apiFetch<MePayload>('/auth/me', {
      method: 'GET',
      organizationId,
      auth: false,
    })
  }

  return {
    register,
    login,
    logout,
    me,
  }
}
