import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { useAuthStore } from '~/stores/auth'
import type { Organization, OrganizationRole } from '~/types/auth'
import { ApiError } from '~/utils/api-error'
import NotificationsSettingsPage from '~/pages/settings/notifications.vue'

const repository = vi.hoisted(() => ({
  updateNotifications: vi.fn(),
}))

vi.mock('~/repositories/organizationRepository', () => ({
  useOrganizationRepository: () => repository,
}))

const user = { id: 'u1', name: 'Owner', email: 'owner@example.com' }

function org(role: OrganizationRole, dailyRevenueGoal: string | null = null): Organization {
  return {
    id: '0199a000-0000-7000-8000-00000000000a',
    name: 'Loja',
    slug: 'loja',
    currency: 'BRL',
    timezone: 'America/Sao_Paulo',
    insights: { available: false, enabled: false },
    daily_revenue_goal: dailyRevenueGoal,
    role,
  }
}

function text(wrapper: VueWrapper) {
  return wrapper.text().replace(/\s+/g, ' ')
}

function button(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('button').find(candidate => candidate.text().includes(label))
}

async function mountPage() {
  const wrapper = await mountSuspended(NotificationsSettingsPage, { route: '/settings/notifications' })
  await flushPromises()

  return wrapper
}

describe('notifications settings page', () => {
  beforeEach(() => {
    repository.updateNotifications.mockReset()
  })

  it('lists the alerts the app receives', async () => {
    useAuthStore().setSession(user, org('owner'))

    const wrapper = await mountPage()

    expect(text(wrapper)).toContain('Estoque baixo')
    expect(text(wrapper)).toContain('Estoque zerado')
    expect(text(wrapper)).toContain('Meta do dia batida')
    expect(text(wrapper)).toContain('Nenhuma meta definida.')
    expect(button(wrapper, 'Remover meta')).toBeUndefined()
    wrapper.unmount()
  })

  it('lets an owner save the goal typed in pt-BR and updates the session organization', async () => {
    useAuthStore().setSession(user, org('owner'))
    repository.updateNotifications.mockResolvedValue(org('owner', '1500.00'))

    const wrapper = await mountPage()
    await wrapper.get('input').setValue('1.500,00')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(repository.updateNotifications).toHaveBeenCalledWith('1500.00')
    expect(useAuthStore().organization?.daily_revenue_goal).toBe('1500.00')
    expect(useAuthStore().organization?.role).toBe('owner')
    expect(text(wrapper)).toContain('Meta atual: R$ 1.500,00.')
    expect(useToast().toasts.value.at(-1)).toMatchObject({ tone: 'success', title: 'Meta diária salva' })
    wrapper.unmount()
  })

  it('asks for a valid amount instead of saving an empty goal', async () => {
    useAuthStore().setSession(user, org('owner'))

    const wrapper = await mountPage()
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(repository.updateNotifications).not.toHaveBeenCalled()
    expect(text(wrapper)).toContain('Informe um valor válido, por exemplo 1.500,00.')
    wrapper.unmount()
  })

  it('removes the goal', async () => {
    useAuthStore().setSession(user, org('owner', '800.00'))
    repository.updateNotifications.mockResolvedValue(org('owner', null))

    const wrapper = await mountPage()
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('800,00')

    await button(wrapper, 'Remover meta')!.trigger('click')
    await flushPromises()

    expect(repository.updateNotifications).toHaveBeenCalledWith(null)
    expect(useAuthStore().organization?.daily_revenue_goal).toBeNull()
    expect(text(wrapper)).toContain('Nenhuma meta definida.')
    wrapper.unmount()
  })

  it('shows the API validation message next to the field', async () => {
    useAuthStore().setSession(user, org('owner'))
    repository.updateNotifications.mockRejectedValue(new ApiError(422, {
      message: 'The daily revenue goal field must be at least 0.01.',
      errors: { daily_revenue_goal: ['The daily revenue goal field must be at least 0.01.'] },
    }))

    const wrapper = await mountPage()
    await wrapper.get('input').setValue('0')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(text(wrapper)).toContain('Informe uma meta maior que zero, com até 2 casas decimais.')
    expect(useAuthStore().organization?.daily_revenue_goal).toBeNull()
    wrapper.unmount()
  })

  it('keeps the goal read-only for members', async () => {
    useAuthStore().setSession(user, org('member', '800.00'))

    const wrapper = await mountPage()

    expect(text(wrapper)).toContain('somente owners alteram a meta diária')
    expect(wrapper.get('input').attributes('disabled')).toBeDefined()
    expect(button(wrapper, 'Salvar meta')).toBeUndefined()
    expect(button(wrapper, 'Remover meta')).toBeUndefined()
    wrapper.unmount()
  })
})
