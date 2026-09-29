import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import { ApiError } from '~/utils/api-error'
import { ORGANIZATION_COOKIE, readOrganizationCookie, writeOrganizationCookie } from '~/utils/organization-cookie'

const repository = vi.hoisted(() => ({
  register: vi.fn(),
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
}))

vi.mock('~/repositories/authRepository', () => ({
  useAuthRepository: () => repository,
}))

const user = { id: 'u1', name: 'Owner', email: 'owner@example.com' }
const orgA: Organization = { id: '0199a000-0000-7000-8000-00000000000a', name: 'A', slug: 'a', currency: 'BRL', timezone: 'America/Sao_Paulo', role: 'owner' }
const orgB: Organization = { id: '0199a000-0000-7000-8000-00000000000b', name: 'B', slug: 'b', currency: 'USD', timezone: 'UTC', role: 'member' }

function mePayload(current: Organization | null) {
  return { user, organizations: [orgA, orgB], current_organization: current }
}

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    writeOrganizationCookie(null)
    Object.values(repository).forEach(mock => mock.mockReset())
  })

  it('bootstraps with the remembered organization and persists the one the API returns', async () => {
    writeOrganizationCookie(orgB.id)
    repository.me.mockResolvedValue(mePayload(orgB))
    const store = useAuthStore()

    await store.bootstrap()

    expect(repository.me).toHaveBeenCalledWith(orgB.id)
    expect(store.organization).toEqual(orgB)
    expect(store.organizations).toHaveLength(2)
    expect(store.bootstrapped).toBe(true)
    expect(readOrganizationCookie()).toBe(orgB.id)
  })

  it('follows the API fallback when the remembered organization is no longer accessible', async () => {
    writeOrganizationCookie('0199a000-0000-7000-8000-0000000000ff')
    repository.me.mockResolvedValue(mePayload(orgA))
    const store = useAuthStore()

    await store.bootstrap()

    expect(store.organization?.id).toBe(orgA.id)
    expect(readOrganizationCookie()).toBe(orgA.id)
  })

  it('ignores a tampered cookie value', async () => {
    document.cookie = `${ORGANIZATION_COOKIE}=not-a-uuid; Path=/`
    repository.me.mockResolvedValue(mePayload(orgA))

    await useAuthStore().bootstrap()

    expect(repository.me).toHaveBeenCalledWith(null)
  })

  it('treats 401 as a known guest state', async () => {
    repository.me.mockRejectedValue(new ApiError(401, { message: 'Unauthenticated.' }))
    const store = useAuthStore()

    await store.bootstrap()

    expect(store.isAuthenticated).toBe(false)
    expect(store.bootstrapped).toBe(true)
  })

  it('surfaces other failures and stays un-bootstrapped so the next navigation retries', async () => {
    repository.me.mockRejectedValue(new ApiError(0, null, 'Network error'))
    const store = useAuthStore()

    await expect(store.bootstrap()).rejects.toBeInstanceOf(ApiError)
    expect(store.bootstrapped).toBe(false)
  })

  it('logs in and reopens the remembered organization', async () => {
    writeOrganizationCookie(orgB.id)
    repository.login.mockResolvedValue({ user, organization: orgA })
    repository.me.mockResolvedValue(mePayload(orgB))
    const store = useAuthStore()

    await store.login({ email: user.email, password: 'password' })

    expect(repository.me).toHaveBeenCalledWith(orgB.id)
    expect(store.organization?.id).toBe(orgB.id)
  })

  it('switches to an organization of the session', () => {
    const store = useAuthStore()
    store.setSession(user, orgA, [orgA, orgB])

    store.selectOrganization(orgB.id)

    expect(store.organization).toEqual(orgB)
    expect(readOrganizationCookie()).toBe(orgB.id)
    expect(() => store.selectOrganization('0199a000-0000-7000-8000-0000000000ff')).toThrow()
  })

  it('recovers from a rejected organization by asking the API for its default', async () => {
    const store = useAuthStore()
    store.setSession(user, orgB, [orgA, orgB])
    repository.me.mockResolvedValue({ user, organizations: [orgA], current_organization: orgA })

    await store.recoverOrganization()

    expect(repository.me).toHaveBeenCalledWith(null)
    expect(store.organization?.id).toBe(orgA.id)
    expect(store.organizations).toEqual([orgA])
  })

  it('clears the session on logout even if the request fails', async () => {
    const store = useAuthStore()
    store.setSession(user, orgA)
    repository.logout.mockRejectedValue(new ApiError(0, null))

    await expect(store.logout()).rejects.toBeInstanceOf(ApiError)
    expect(store.isAuthenticated).toBe(false)
  })
})
