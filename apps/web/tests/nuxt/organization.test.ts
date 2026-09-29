import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import { useOrganization } from '~/composables/useOrganization'
import { usePermissions } from '~/composables/usePermissions'

const { navigateToMock, clearNuxtDataMock } = vi.hoisted(() => ({
  navigateToMock: vi.fn(),
  clearNuxtDataMock: vi.fn(),
}))

mockNuxtImport('navigateTo', () => navigateToMock)
mockNuxtImport('clearNuxtData', () => clearNuxtDataMock)

const user = { id: 'u1', name: 'Owner', email: 'owner@example.com' }
const orgA: Organization = { id: '0199a000-0000-7000-8000-00000000000a', name: 'A', slug: 'a', currency: 'BRL', timezone: 'America/Sao_Paulo', role: 'owner' }
const orgB: Organization = { id: '0199a000-0000-7000-8000-00000000000b', name: 'B', slug: 'b', currency: 'USD', timezone: 'UTC', role: 'member' }

describe('useOrganization', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    navigateToMock.mockReset()
    clearNuxtDataMock.mockReset()
  })

  it('exposes the organization context', () => {
    useAuthStore().setSession(user, orgB, [orgA, orgB])
    const { currency, timezone, hasMultipleOrganizations } = useOrganization()

    expect(currency.value).toBe('USD')
    expect(timezone.value).toBe('UTC')
    expect(hasMultipleOrganizations.value).toBe(true)
  })

  it('switches organization, drops cached data and returns to the dashboard', async () => {
    const store = useAuthStore()
    store.setSession(user, orgA, [orgA, orgB])

    await useOrganization().switchOrganization(orgB.id)

    expect(store.organization?.id).toBe(orgB.id)
    expect(clearNuxtDataMock).toHaveBeenCalledOnce()
    expect(navigateToMock).toHaveBeenCalledWith('/dashboard')
  })

  it('does nothing when the organization is already selected', async () => {
    useAuthStore().setSession(user, orgA, [orgA, orgB])

    await useOrganization().switchOrganization(orgA.id)

    expect(clearNuxtDataMock).not.toHaveBeenCalled()
    expect(navigateToMock).not.toHaveBeenCalled()
  })
})

describe('usePermissions', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('allows deletes only for owners', () => {
    const store = useAuthStore()
    store.setSession(user, orgA, [orgA, orgB])
    const { isOwner, canDelete } = usePermissions()
    expect(isOwner.value).toBe(true)
    expect(canDelete.value).toBe(true)

    store.selectOrganization(orgB.id)
    expect(isOwner.value).toBe(false)
    expect(canDelete.value).toBe(false)
  })
})
