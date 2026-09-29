import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockComponent, mountSuspended } from '@nuxt/test-utils/runtime'
import { h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import type {
  CustomerAnalyticsResponse,
  DashboardResponse,
  ProductAnalyticsResponse,
  RevenueResponse,
  TransactionStatusResponse,
} from '~/types/analytics'
import { ApiError } from '~/utils/api-error'
import DashboardPage from '~/pages/dashboard.vue'

const repository = vi.hoisted(() => ({
  dashboard: vi.fn(),
  revenue: vi.fn(),
  transactionStatus: vi.fn(),
  products: vi.fn(),
  customers: vi.fn(),
}))

vi.mock('~/repositories/analyticsRepository', () => ({
  useAnalyticsRepository: () => repository,
}))

const org: Organization = {
  id: '0199a000-0000-7000-8000-00000000000a', name: 'Loja', slug: 'loja', currency: 'BRL', timezone: 'America/Sao_Paulo', role: 'member',
}

const meta = {
  period: { from: '2026-08-31', to: '2026-09-29', days: 30 },
  previous_period: { from: '2026-08-01', to: '2026-08-30', days: 30 },
  timezone: 'America/Sao_Paulo',
  currency: 'BRL',
}

// Shapes copied from real API responses.
const dashboard: DashboardResponse = {
  data: {
    revenue: { value: '96962.20', previous: '90308.20', change: 7.4 },
    orders: { value: 121, previous: 149, change: -18.8 },
    average_order_value: { value: '801.34', previous: '606.10', change: 32.2 },
    customers: { value: 46, previous: 48, change: -4.2 },
  },
  meta,
}

const revenue: RevenueResponse = {
  data: [
    { bucket: '2026-08-31', from: '2026-08-31', to: '2026-08-31', revenue: '1200.00', orders: 2 },
    { bucket: '2026-09-01', from: '2026-09-01', to: '2026-09-01', revenue: '0.00', orders: 0 },
  ],
  summary: {
    revenue: { value: '96962.20', previous: '90308.20', change: 7.4 },
    orders: { value: 121, previous: 149, change: -18.8 },
  },
  meta: { ...meta, granularity: 'day' },
}

const status: TransactionStatusResponse = {
  data: [
    { status: 'paid', orders: { value: 121, previous: 149, change: -18.8 }, revenue: { value: '96962.20', previous: '90308.20', change: 7.4 }, percentage: { value: 81.8, previous: 87.6, change: -6.6 } },
    { status: 'refunded', orders: { value: 14, previous: 10, change: 40 }, revenue: { value: '6546.10', previous: '6627.80', change: -1.2 }, percentage: { value: 9.5, previous: 5.9, change: 61 } },
    { status: 'pending', orders: { value: 7, previous: 1, change: 600 }, revenue: { value: '5698.40', previous: '3349.70', change: 70.1 }, percentage: { value: 4.7, previous: 0.6, change: 683.3 } },
    { status: 'canceled', orders: { value: 6, previous: 10, change: -40 }, revenue: { value: '9098.40', previous: '5972.00', change: 52.4 }, percentage: { value: 4.1, previous: 5.9, change: -30.5 } },
  ],
  meta,
}

const products: ProductAnalyticsResponse = {
  data: [
    { rank: 1, product: { id: 'p1', name: 'Cadeira ergonômica Pro', sku: 'PB-012-PRO', status: 'active', is_deleted: false }, revenue: { value: '20148.70', previous: '9299.40', change: 116.7 }, units_sold: { value: 13, previous: 6, change: 116.7 } },
    { rank: 2, product: { id: 'p2', name: 'Mesa descontinuada', sku: null, status: 'inactive', is_deleted: true }, revenue: { value: '11548.90', previous: '0.00', change: null }, units_sold: { value: 11, previous: 0, change: null } },
  ],
  summary: {
    revenue: { value: '96962.20', previous: '90308.20', change: 7.4 },
    units_sold: { value: 288, previous: 348, change: -17.2 },
    products_sold: { value: 34, previous: 38, change: -10.5 },
  },
  meta: { ...meta, sort: 'revenue', limit: 5 },
}

const customers: CustomerAnalyticsResponse = {
  data: [
    { rank: 1, customer: { id: 'c1', name: 'Josué Franco', email: 'orempel@example.com', is_deleted: false }, revenue: { value: '12650.90', previous: '2302.10', change: 449.5 }, orders: { value: 13, previous: 5, change: 160 } },
  ],
  summary: {
    total_customers: { value: 70, previous: 70, change: 0 },
    active_customers: { value: 46, previous: 48, change: -4.2 },
    new_customers: { value: 7, previous: 13, change: -46.2 },
    returning_customers: { value: 39, previous: 35, change: 11.4 },
  },
  meta: { ...meta, sort: 'revenue', limit: 5 },
}

mockComponent('~/components/charts/RevenueChart.client.vue', {
  setup: () => () => h('div', { 'data-chart': '' }),
})
mockComponent('~/components/charts/StatusDonutChart.client.vue', {
  setup: (_props, { slots }) => () => h('div', slots.default?.()),
})

function section(wrapper: VueWrapper, title: string) {
  const card = wrapper.findAll('section').find(node => node.find('h2').exists() && node.find('h2').text() === title)

  if (!card) {
    throw new Error(`Section "${title}" not found`)
  }

  return card
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-29T15:00:00Z') })
  Object.values(repository).forEach(mock => mock.mockReset())
  repository.dashboard.mockResolvedValue(dashboard)
  repository.revenue.mockResolvedValue(revenue)
  repository.transactionStatus.mockResolvedValue(status)
  repository.products.mockResolvedValue(products)
  repository.customers.mockResolvedValue(customers)
  clearNuxtData()
  useAuthStore().setSession({ id: 'u1', name: 'Member', email: 'member@example.com' }, org)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('dashboard page', () => {
  it('requests the five sections with the same period', async () => {
    const wrapper = await mountSuspended(DashboardPage, { route: '/dashboard?period=7d' })
    await flushPromises()

    const period = { from: '2026-09-23', to: '2026-09-29' }
    expect(repository.dashboard).toHaveBeenCalledWith(period)
    expect(repository.revenue).toHaveBeenCalledWith({ ...period, granularity: 'day' })
    expect(repository.transactionStatus).toHaveBeenCalledWith(period)
    expect(repository.products).toHaveBeenCalledWith({ ...period, limit: 5 })
    expect(repository.customers).toHaveBeenCalledWith({ ...period, limit: 5 })
    wrapper.unmount()
  })

  it('shows the API numbers with their comparison and the period caption', async () => {
    const wrapper = await mountSuspended(DashboardPage, { route: '/dashboard' })
    await flushPromises()

    const text = wrapper.text().replace(/\s+/g, ' ')
    expect(text).toContain('31 ago – 29 set 2026 · comparado a 01 – 30 ago 2026')
    expect(text).toMatch(/Receita\s*R\$\s96\.962,20/)
    expect(text).toMatch(/vs R\$ 90\.308,20 no período anterior/)
    expect(text).toContain('Aumento de 7,4% em relação ao período anterior.')
    expect(text).toMatch(/Pedidos pagos\s*121/)
    expect(text).toContain('Queda de 18,8% em relação ao período anterior.')
    expect(text).toMatch(/Ticket médio\s*R\$\s801,34/)
    expect(text).toMatch(/Clientes ativos\s*46/)

    const statusCard = section(wrapper, 'Transações por status').text().replace(/\s+/g, ' ')
    expect(statusCard).toMatch(/148\s*transações/)
    expect(statusCard).toMatch(/Reembolsado\s*14\s*9,5%/)
    wrapper.unmount()
  })

  it('shows "—" for an average order value without orders', async () => {
    repository.dashboard.mockResolvedValue({
      ...dashboard,
      data: {
        revenue: { value: '0.00', previous: '0.00', change: 0 },
        orders: { value: 0, previous: 0, change: 0 },
        average_order_value: { value: null, previous: null, change: null },
        customers: { value: 0, previous: 0, change: 0 },
      },
    })

    const wrapper = await mountSuspended(DashboardPage, { route: '/dashboard' })
    await flushPromises()

    const text = wrapper.text().replace(/\s+/g, ' ')
    expect(text).toMatch(/Ticket médio\s*—.*vs — no período anterior/)
    expect(text).toContain('Sem comparação disponível.')
    expect(text).toContain('Estável em relação ao período anterior.')
    wrapper.unmount()
  })

  it('links ranked entities except removed ones, and keeps the period in "Ver análise"', async () => {
    const wrapper = await mountSuspended(DashboardPage, { route: '/dashboard?period=90d' })
    await flushPromises()

    const card = section(wrapper, 'Top 5 produtos')
    const hrefs = card.findAll('a').map(link => link.attributes('href'))

    expect(hrefs).toContain('/products/p1')
    expect(hrefs).not.toContain('/products/p2')
    expect(hrefs).toContain('/analytics/products?period=90d')
    expect(card.text()).toContain('Mesa descontinuada')
    expect(card.text()).toContain('Removido')
    expect(card.text()).toContain('Novo')
    wrapper.unmount()
  })

  it('isolates a failing section and retries only that request', async () => {
    repository.products.mockRejectedValueOnce(new ApiError(500, { message: 'Server Error' }))

    const wrapper = await mountSuspended(DashboardPage, { route: '/dashboard' })
    await flushPromises()

    const card = section(wrapper, 'Top 5 produtos')
    expect(card.find('[role="alert"]').exists()).toBe(true)
    expect(section(wrapper, 'Top 5 clientes').text()).toContain('Josué Franco')
    expect(wrapper.text()).toMatch(/Receita\s*R\$\s96\.962,20/)

    await card.get('[role="alert"] button').trigger('click')
    await flushPromises()

    expect(repository.products).toHaveBeenCalledTimes(2)
    expect(repository.customers).toHaveBeenCalledTimes(1)
    expect(section(wrapper, 'Top 5 produtos').text()).toContain('Cadeira ergonômica Pro')
    wrapper.unmount()
  })

  it('shows an empty state per card for a period without sales', async () => {
    const zero = { value: 0, previous: 0, change: 0 }
    const zeroMoney = { value: '0.00', previous: '0.00', change: 0 }
    repository.revenue.mockResolvedValue({ ...revenue, summary: { revenue: zeroMoney, orders: zero } })
    repository.transactionStatus.mockResolvedValue({
      ...status,
      data: status.data.map(row => ({ ...row, orders: zero, revenue: zeroMoney, percentage: { value: null, previous: null, change: null } })),
    })
    repository.products.mockResolvedValue({ ...products, data: [] })
    repository.customers.mockResolvedValue({ ...customers, data: [] })

    const wrapper = await mountSuspended(DashboardPage, { route: '/dashboard' })
    await flushPromises()

    expect(wrapper.text()).toContain('Nenhuma venda no período')
    expect(wrapper.text()).toContain('Nenhuma transação no período')
    expect(wrapper.text()).toContain('Nenhum produto vendido no período')
    expect(wrapper.text()).toContain('Nenhum cliente comprou no período')
    wrapper.unmount()
  })

  it('refetches only the revenue series when the granularity changes', async () => {
    const wrapper = await mountSuspended(DashboardPage, { route: '/dashboard' })
    await flushPromises()

    await wrapper.findAll('button').find(button => button.text() === 'Semana')!.trigger('click')

    await vi.waitFor(() => expect(repository.revenue).toHaveBeenLastCalledWith({ from: '2026-08-31', to: '2026-09-29', granularity: 'week' }))
    expect(useRouter().currentRoute.value.query).toEqual({ granularity: 'week' })
    expect(repository.dashboard).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})
