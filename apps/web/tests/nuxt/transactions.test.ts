import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import type { Transaction, TransactionDetail } from '~/types/transaction'
import { ApiError } from '~/utils/api-error'
import TransactionsPage from '~/pages/transactions/index.vue'
import TransactionPage from '~/pages/transactions/[id].vue'
import { paginated } from '../support/dom'

const repository = vi.hoisted(() => ({ list: vi.fn(), get: vi.fn() }))

vi.mock('~/repositories/transactionRepository', () => ({
  useTransactionRepository: () => repository,
}))

const user = { id: 'u1', name: 'Member', email: 'member@example.com' }
const org: Organization = {
  id: '0199a000-0000-7000-8000-00000000000a', name: 'Loja', slug: 'loja', currency: 'BRL', timezone: 'America/Sao_Paulo', insights: { available: false, enabled: false }, role: 'member',
}

const customerId = '0199a000-0000-7000-8000-0000000000c1'

const transaction: Transaction = {
  id: '0199a000-0000-7000-8000-0000000000t1',
  external_id: null,
  source: 'seed',
  status: 'paid',
  total_amount: '1234.50',
  // 02:30 UTC on Sep 2 is still Sep 1 in São Paulo (UTC−3).
  occurred_at: '2026-09-02T02:30:00Z',
  items_count: 2,
  customer: { id: customerId, name: 'Maria Souza', email: 'maria@example.com', is_deleted: false },
}

const removedCustomerTransaction: Transaction = {
  ...transaction,
  id: '0199a000-0000-7000-8000-0000000000t2',
  customer: { id: '0199a000-0000-7000-8000-0000000000c2', name: 'João Removido', email: 'joao@example.com', is_deleted: true },
}

beforeEach(() => {
  Object.values(repository).forEach(mock => mock.mockReset())
  document.body.innerHTML = ''
  clearNuxtData()
  useAuthStore().setSession(user, org)
})

describe('transactions page', () => {
  it('shows occurred_at in the organization timezone, near midnight UTC', async () => {
    repository.list.mockResolvedValue(paginated([transaction]))

    const wrapper = await mountSuspended(TransactionsPage, { route: '/transactions' })
    await flushPromises()

    expect(wrapper.text()).toContain('01/09/2026 23:30')
    expect(wrapper.text()).not.toContain('02/09/2026')
    expect(wrapper.text()).toMatch(/R\$\s1\.234,50/)
    wrapper.unmount()
  })

  it('sends the URL filters to the API and names the customer filter chip', async () => {
    repository.list.mockResolvedValue(paginated([transaction]))

    const wrapper = await mountSuspended(TransactionsPage, {
      route: `/transactions?status=paid&from=2026-09-01&customer_id=${customerId}&q=maria`,
    })
    await flushPromises()

    expect(repository.list).toHaveBeenLastCalledWith({
      q: 'maria', status: 'paid', customer_id: customerId, from: '2026-09-01', page: 1, per_page: 15,
    })
    expect(wrapper.text()).toContain('Cliente: Maria Souza')
    wrapper.unmount()
  })

  it('drops malformed filters from the URL instead of sending them', async () => {
    repository.list.mockResolvedValue(paginated([]))

    const wrapper = await mountSuspended(TransactionsPage, {
      route: '/transactions?status=archived&from=01-09-2026&customer_id=42',
    })
    await flushPromises()

    expect(repository.list).toHaveBeenLastCalledWith({ page: 1, per_page: 15 })
    expect(wrapper.text()).toContain('Nenhuma transação registrada')
    wrapper.unmount()
  })

  it('updates the URL when a filter chip is removed', async () => {
    repository.list.mockResolvedValue(paginated([transaction]))

    const wrapper = await mountSuspended(TransactionsPage, { route: `/transactions?customer_id=${customerId}&page=2` })
    await flushPromises()

    await wrapper.get('button[aria-label="Remover filtro de cliente"]').trigger('click')

    await vi.waitFor(() => expect(useRouter().currentRoute.value.fullPath).toBe('/transactions'))
    await vi.waitFor(() => expect(repository.list).toHaveBeenLastCalledWith({ page: 1, per_page: 15 }))
    wrapper.unmount()
  })

  it('shows the origin of each transaction and the external id of ingested ones', async () => {
    repository.list.mockResolvedValue(paginated([
      { ...transaction, id: '0199a000-0000-7000-8000-0000000000t3', source: 'ingest', external_id: 'order_1001' },
      transaction,
    ]))

    const wrapper = await mountSuspended(TransactionsPage, { route: '/transactions' })
    await flushPromises()

    const rows = wrapper.findAll('tbody tr')
    expect(rows[0]!.text()).toContain('Integração')
    expect(rows[0]!.text()).toContain('order_1001')
    expect(rows[1]!.text()).toContain('Demo')
    wrapper.unmount()
  })

  it('marks removed customers without linking to them', async () => {
    repository.list.mockResolvedValue(paginated([removedCustomerTransaction]))

    const wrapper = await mountSuspended(TransactionsPage, { route: '/transactions' })
    await flushPromises()

    expect(wrapper.text()).toContain('Removido')
    expect(wrapper.findAll('a').map(link => link.attributes('href'))).not.toContain(`/customers/${removedCustomerTransaction.customer.id}`)
    wrapper.unmount()
  })

  it('shows the translated API validation error for an inverted period', async () => {
    repository.list.mockRejectedValue(new ApiError(422, {
      message: 'The to date must be on or after the from date.',
      errors: { to: ['The to date must be on or after the from date.'] },
    }))

    const wrapper = await mountSuspended(TransactionsPage, { route: '/transactions?from=2026-09-10&to=2026-09-01' })
    await flushPromises()

    expect(wrapper.text()).toContain('A data final deve ser igual ou posterior à inicial.')
    wrapper.unmount()
  })
})

describe('transaction detail page', () => {
  const detail: TransactionDetail = {
    ...transaction,
    items: [
      { id: 'i1', quantity: 2, unit_price: '500.00', line_total: '1000.00', product: { id: 'p1', name: 'Cadeira', sku: 'CAD-1', is_deleted: false } },
      { id: 'i2', quantity: 1, unit_price: '234.50', line_total: '234.50', product: { id: 'p2', name: 'Mesa antiga', sku: null, is_deleted: true } },
    ],
    status_history: [
      { from_status: null, to_status: 'paid', occurred_at: '2026-09-02T02:30:00Z', recorded_at: '2026-09-02T02:30:00Z', source: 'seed' },
    ],
  }

  /** Same occurred_at on purpose: the page must keep the API order, not sort by time. */
  const ingested: TransactionDetail = {
    ...detail,
    external_id: 'order_1001',
    source: 'ingest',
    status: 'refunded',
    status_history: [
      { from_status: null, to_status: 'pending', occurred_at: '2026-09-02T02:30:00Z', recorded_at: '2026-09-02T02:30:00Z', source: 'ingest' },
      { from_status: 'pending', to_status: 'paid', occurred_at: '2026-09-02T02:30:00Z', recorded_at: '2026-09-02T02:30:00Z', source: 'ingest' },
      { from_status: 'paid', to_status: 'refunded', occurred_at: '2026-09-05T15:00:00Z', recorded_at: '2026-09-05T15:10:00Z', source: 'ingest' },
    ],
  }

  it('renders the status history exactly in the order returned by the API', async () => {
    repository.get.mockResolvedValue(ingested)

    const wrapper = await mountSuspended(TransactionPage, { route: `/transactions/${transaction.id}` })
    await flushPromises()

    const steps = wrapper.findAll('ol[aria-label="Histórico de status"] > li')
    expect(steps.map(step => step.find('p').text())).toEqual([
      'Criada como Pendente',
      'Pendente → Pago',
      'Pago → Reembolsado',
    ])
    expect(steps.map(step => step.attributes('aria-current'))).toEqual([undefined, undefined, 'step'])
    expect(steps[2]!.text()).toContain('Status atual')
    // Business time in the organization timezone; the later recording time is shown apart.
    expect(steps[2]!.text()).toContain('05/09/2026 12:00')
    expect(steps[2]!.text()).toContain('Registrada em 05/09/2026 12:10')
    expect(steps[0]!.text()).not.toContain('Registrada em')

    const sequence = wrapper.findAll('ol[aria-label="Sequência de status"] > li').map(step => step.text())
    expect(sequence).toEqual(['null', 'Pendente', 'Pago', 'Reembolsado'])
    wrapper.unmount()
  })

  it('shows the integration origin and external id of an ingested transaction', async () => {
    repository.get.mockResolvedValue(ingested)

    const wrapper = await mountSuspended(TransactionPage, { route: `/transactions/${transaction.id}` })
    await flushPromises()

    expect(wrapper.text()).toContain('Integração')
    expect(wrapper.text()).toContain('order_1001')
    expect(wrapper.text()).toContain('GET /ingest/transactions/order_1001')
    expect(wrapper.find('button[aria-label="Copiar ID externo"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('explains why a seeded transaction has no external id', async () => {
    repository.get.mockResolvedValue(detail)

    const wrapper = await mountSuspended(TransactionPage, { route: `/transactions/${transaction.id}` })
    await flushPromises()

    expect(wrapper.text()).toContain('Demo')
    expect(wrapper.text()).toContain('Sem ID externo')
    expect(wrapper.find('button[aria-label="Copiar ID externo"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Criada como Pago')
    wrapper.unmount()
  })

  it('shows an empty state when the API returns no history', async () => {
    repository.get.mockResolvedValue({ ...detail, status_history: [] })

    const wrapper = await mountSuspended(TransactionPage, { route: `/transactions/${transaction.id}` })
    await flushPromises()

    expect(wrapper.text()).toContain('Sem histórico de status')
    wrapper.unmount()
  })

  it('shows the request id when the API fails', async () => {
    repository.get.mockRejectedValue(new ApiError(500, { message: 'Server Error' }, undefined, { requestId: 'req-12345678' }))

    const wrapper = await mountSuspended(TransactionPage, { route: `/transactions/${transaction.id}` })
    await flushPromises()

    expect(wrapper.text()).toContain('O servidor encontrou um erro')
    expect(wrapper.text()).toContain('ID da requisição: req-12345678')
    wrapper.unmount()
  })

  it('lists items with prices at the time of sale and flags removed products', async () => {
    repository.get.mockResolvedValue(detail)

    const wrapper = await mountSuspended(TransactionPage, { route: `/transactions/${transaction.id}` })
    await flushPromises()

    const hrefs = wrapper.findAll('a').map(link => link.attributes('href'))
    expect(hrefs).toContain('/products/p1')
    expect(hrefs).not.toContain('/products/p2')
    expect(hrefs).toContain(`/customers/${customerId}`)
    expect(wrapper.text()).toContain('Mesa antiga')
    expect(wrapper.text()).toContain('Removido')
    expect(wrapper.text()).toContain('preços no momento da venda')
    expect(wrapper.text()).toMatch(/Total da transação\s*R\$\s1\.234,50/)
    expect(wrapper.text()).not.toMatch(/Editar|Excluir/)
    wrapper.unmount()
  })

  it('shows a friendly message for a foreign or unknown transaction (404)', async () => {
    repository.get.mockRejectedValue(new ApiError(404, { message: 'Not found.' }))

    const wrapper = await mountSuspended(TransactionPage, { route: `/transactions/${transaction.id}` })
    await flushPromises()

    expect(wrapper.text()).toContain('Transação não encontrada')
    wrapper.unmount()
  })
})
