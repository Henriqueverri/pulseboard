import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import type { Customer, CustomerDetail } from '~/types/customer'
import { ApiError } from '~/utils/api-error'
import { changedCustomerFields } from '~/composables/useCustomers'
import CustomerFormDialog from '~/components/customers/CustomerFormDialog.vue'
import CustomersPage from '~/pages/customers/index.vue'
import CustomerPage from '~/pages/customers/[id].vue'
import { clickButton, field, menuItems, paginated, type } from '../support/dom'

const repository = vi.hoisted(() => ({
  list: vi.fn(),
  get: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  remove: vi.fn(),
}))

vi.mock('~/repositories/customerRepository', () => ({
  useCustomerRepository: () => repository,
}))

const user = { id: 'u1', name: 'Owner', email: 'owner@example.com' }
const org = (role: 'owner' | 'member'): Organization => ({
  id: '0199a000-0000-7000-8000-00000000000a', name: 'Loja', slug: 'loja', currency: 'BRL', timezone: 'America/Sao_Paulo', role,
})

const customer: Customer = {
  id: '0199a000-0000-7000-8000-0000000000c1',
  name: 'Maria Souza',
  email: 'maria@example.com',
  created_at: '2026-09-01T12:00:00Z',
  updated_at: '2026-09-01T12:00:00Z',
}

const detail = (recent: CustomerDetail['recent_transactions']): CustomerDetail => ({
  ...customer, orders_count: 3, total_spent: '1250.00', recent_transactions: recent,
})

beforeEach(() => {
  Object.values(repository).forEach(mock => mock.mockReset())
  document.body.innerHTML = ''
  clearNuxtData()
})

describe('changedCustomerFields', () => {
  it('ignores case-only email changes (the API lowercases emails)', () => {
    expect(changedCustomerFields(customer, { name: 'Maria Souza', email: 'MARIA@example.com' })).toEqual({})
    expect(changedCustomerFields(customer, { name: 'Maria S.', email: 'maria.s@example.com' }))
      .toEqual({ name: 'Maria S.', email: 'maria.s@example.com' })
  })
})

describe('CustomerFormDialog', () => {
  beforeEach(() => useAuthStore().setSession(user, org('owner')))

  it('creates with trimmed values', async () => {
    repository.create.mockResolvedValue(customer)
    const wrapper = await mountSuspended(CustomerFormDialog, { props: { open: true }, attachTo: document.body })

    await type(field('Nome'), ' Maria Souza ')
    await type(field('E-mail'), ' maria@example.com ')
    await clickButton('Criar cliente')

    expect(repository.create).toHaveBeenCalledWith({ name: 'Maria Souza', email: 'maria@example.com' })
    expect(wrapper.emitted('saved')?.[0]).toEqual([customer, 'created'])
    wrapper.unmount()
  })

  it('explains a duplicated email, including removed customers', async () => {
    const message = 'This email is already used by another customer in this organization, including deleted customers.'
    repository.create.mockRejectedValue(new ApiError(422, { message, errors: { email: [message] } }))
    const wrapper = await mountSuspended(CustomerFormDialog, { props: { open: true }, attachTo: document.body })

    await type(field('Nome'), 'Outra Maria')
    await type(field('E-mail'), 'maria@example.com')
    await clickButton('Criar cliente')

    expect(document.body.textContent).toContain('Este e-mail já está em uso por outro cliente desta organização (incluindo clientes removidos).')
    expect(field('E-mail').getAttribute('aria-invalid')).toBe('true')
    wrapper.unmount()
  })
})

describe('customers page', () => {
  async function rowMenu(role: 'owner' | 'member') {
    useAuthStore().setSession(user, org(role))
    repository.list.mockResolvedValue(paginated([customer]))
    const wrapper = await mountSuspended(CustomersPage, { attachTo: document.body })
    await flushPromises()

    await wrapper.get(`button[aria-label="Ações de ${customer.name}"]`).trigger('keydown', { key: 'Enter' })
    await flushPromises()
    const items = menuItems()
    wrapper.unmount()

    return items
  }

  it('hides delete from members', async () => {
    expect(await rowMenu('owner')).toEqual(['Editar', 'Excluir'])
    document.body.innerHTML = ''
    clearNuxtData()
    expect(await rowMenu('member')).toEqual(['Editar'])
  })
})

describe('customer detail page', () => {
  beforeEach(() => useAuthStore().setSession(user, org('member')))

  it('shows paid totals and an empty recent transactions state', async () => {
    repository.get.mockResolvedValue(detail([]))

    const wrapper = await mountSuspended(CustomerPage, { route: `/customers/${customer.id}` })
    await flushPromises()

    expect(repository.get).toHaveBeenCalledWith(customer.id)
    expect(wrapper.text()).toContain('Pedidos pagos')
    expect(wrapper.text()).toMatch(/R\$\s1\.250,00/)
    expect(wrapper.text()).toContain('Nenhuma transação')
    expect(wrapper.text()).not.toContain('Ver todas')
    expect(wrapper.text()).not.toContain('Excluir')
    wrapper.unmount()
  })

  it('links recent transactions and the filtered transaction list', async () => {
    repository.get.mockResolvedValue(detail([
      { id: '0199a000-0000-7000-8000-0000000000t1', status: 'refunded', total_amount: '80.00', occurred_at: '2026-09-02T02:30:00Z' },
    ]))

    const wrapper = await mountSuspended(CustomerPage, { route: `/customers/${customer.id}` })
    await flushPromises()

    const hrefs = wrapper.findAll('a').map(link => link.attributes('href'))
    expect(hrefs).toContain('/transactions/0199a000-0000-7000-8000-0000000000t1')
    expect(hrefs).toContain(`/transactions?customer_id=${customer.id}`)
    expect(wrapper.text()).toContain('Reembolsado')
    expect(wrapper.text()).toContain('01/09/2026 23:30')
    wrapper.unmount()
  })

  it('shows a friendly message for removed or foreign customers (404)', async () => {
    repository.get.mockRejectedValue(new ApiError(404, { message: 'Not found.' }))

    const wrapper = await mountSuspended(CustomerPage, { route: `/customers/${customer.id}` })
    await flushPromises()

    expect(wrapper.text()).toContain('Cliente não encontrado')
    wrapper.unmount()
  })
})
