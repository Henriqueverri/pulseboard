import type { AuthPayload, MePayload } from '~/types/auth'

export function useAuthRepository() {
  const { apiFetch } = useApiClient()

  function register(payload: {
    name: string
    email: string
    password: string
    password_confirmation: string
  }) {
    return apiFetch<AuthPayload>('/auth/register', {
      method: 'POST',
      body: payload,
      auth: false,
      organizationId: null,
    })
  }

  function login(payload: { email: string, password: string }) {
    return apiFetch<AuthPayload>('/auth/login', {
      method: 'POST',
      body: payload,
      auth: false,
      organizationId: null,
    })
  }

  function logout() {
    return apiFetch<{ message: string }>('/auth/logout', {
      method: 'POST',
      organizationId: null,
    })
  }

  function me() {
    return apiFetch<MePayload>('/auth/me', {
      method: 'GET',
      organizationId: null,
    })
  }

  return {
    register,
    login,
    logout,
    me,
  }
}
