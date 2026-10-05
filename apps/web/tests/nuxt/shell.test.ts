import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import OrganizationSwitcher from '~/components/layout/OrganizationSwitcher.vue'
import { SIDEBAR_STORAGE_KEY, useSidebar } from '~/composables/useSidebar'

const { navigateToMock, clearNuxtDataMock } = vi.hoisted(() => ({
  navigateToMock: vi.fn(),
  clearNuxtDataMock: vi.fn(),
}))

mockNuxtImport('navigateTo', () => navigateToMock)
mockNuxtImport('clearNuxtData', () => clearNuxtDataMock)

const user = { id: 'u1', name: 'Owner', email: 'owner@example.com' }
const orgA: Organization = { id: '0199a000-0000-7000-8000-00000000000a', name: 'Loja A', slug: 'a', currency: 'BRL', timezone: 'America/Sao_Paulo', insights: { available: false, enabled: false }, role: 'owner' }
const orgB: Organization = { id: '0199a000-0000-7000-8000-00000000000b', name: 'Loja B', slug: 'b', currency: 'USD', timezone: 'UTC', insights: { available: false, enabled: false }, role: 'member' }

describe('useSidebar', () => {
  beforeEach(() => localStorage.clear())

  it('persists the desktop collapse preference', async () => {
    const { collapsed, toggleCollapsed } = useSidebar()
    expect(collapsed.value).toBe(false)

    toggleCollapsed()
    await flushPromises()

    expect(collapsed.value).toBe(true)
    expect(localStorage.getItem(SIDEBAR_STORAGE_KEY)).toBe('true')
  })

  it('opens and closes the navigation drawer', () => {
    const { drawerOpen, openDrawer, closeDrawer } = useSidebar()

    openDrawer()
    expect(drawerOpen.value).toBe(true)
    closeDrawer()
    expect(drawerOpen.value).toBe(false)
  })
})

describe('OrganizationSwitcher', () => {
  beforeEach(() => {
    navigateToMock.mockReset()
    clearNuxtDataMock.mockReset()
  })

  it('shows a static label when the user has a single organization', async () => {
    useAuthStore().setSession(user, orgA, [orgA])

    const wrapper = await mountSuspended(OrganizationSwitcher)

    expect(wrapper.text()).toContain('Loja A')
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('switches organization from the menu', async () => {
    const store = useAuthStore()
    store.setSession(user, orgA, [orgA, orgB])

    const wrapper = await mountSuspended(OrganizationSwitcher, { attachTo: document.body })
    const trigger = wrapper.get('button[aria-label="Trocar organização"]')
    expect(trigger.text()).toContain('Loja A')

    await trigger.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    const option = [...document.querySelectorAll<HTMLElement>('[role="menuitem"]')]
      .find(element => element.textContent?.includes('Loja B'))
    expect(option).toBeDefined()

    option!.click()
    await flushPromises()

    expect(store.organization?.id).toBe(orgB.id)
    expect(clearNuxtDataMock).toHaveBeenCalledOnce()
    expect(navigateToMock).toHaveBeenCalledWith('/dashboard')

    wrapper.unmount()
  })
})
