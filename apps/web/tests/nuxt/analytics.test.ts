import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockComponent, mountSuspended } from '@nuxt/test-utils/runtime'
import { h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import type {
  CustomerAnalyticsResponse,
  ProductAnalyticsResponse,
  RevenueResponse,
  TransactionStatusResponse,
} from '~/types/analytics'
import { useRankingQuery } from '~/composables/useAnalytics'
import AnalyticsPage from '~/pages/analytics.vue'
import RevenuePage from '~/pages/analytics/revenue.vue'
import ProductsPage from '~/pages/analytics/products.vue'
import CustomersPage from '~/pages/analytics/customers.vue'
import TransactionsPage from '~/pages/analytics/transactions.vue'

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

const revenue: RevenueResponse = {
  data: [
    { bucket: '2026-08-31', from: '2026-08-31', to: '2026-09-06', revenue: '23869.20', orders: 22 },
    { bucket: '2026-09-28', from: '2026-09-28', to: '2026-09-29', revenue: '1274.40', orders: 2 },
  ],
  summary: {
    revenue: { value: '25143.60', previous: '95112.60', change: -73.6 },
    orders: { value: 24, previous: 151, change: -84.1 },
  },
  meta: { ...meta, granularity: 'week' },
}

const products: ProductAnalyticsResponse = {
  data: [
    { rank: 1, product: { id: 'p1', name: 'Cadeira ergonômica Pro', sku: 'PB-012-PRO', status: 'active', is_deleted: false }, revenue: { value: '20148.70', previous: '9299.40', change: 116.7 }, units_sold: { value: 13, previous: 6, change: 116.7 } },
    { rank: 2, product: { id: 'p2', name: 'Monitor antigo', sku: null, status: 'inactive', is_deleted: false }, revenue: { value: '900.00', previous: '0.00', change: null }, units_sold: { value: 1, previous: 0, change: null } },
    { rank: 3, product: { id: 'p3', name: 'Mesa descontinuada', sku: 'MS-1', status: 'active', is_deleted: true }, revenue: { value: '500.00', previous: '800.00', change: -37.5 }, units_sold: { value: 1, previous: 2, change: -50 } },
  ],
  summary: {
    revenue: { value: '96962.20', previous: '90308.20', change: 7.4 },
    units_sold: { value: 288, previous: 348, change: -17.2 },
    products_sold: { value: 34, previous: 38, change: -10.5 },
  },
  meta: { ...meta, sort: 'revenue', limit: 10 },
}

const customers: CustomerAnalyticsResponse = {
  data: [
    { rank: 1, customer: { id: 'c1', name: 'Josué Franco', email: 'orempel@example.com', is_deleted: false }, revenue: { value: '12650.90', previous: '2302.10', change: 449.5 }, orders: { value: 13, previous: 5, change: 160 } },
    { rank: 2, customer: { id: 'c2', name: 'Ana Removida', email: 'ana@example.com', is_deleted: true }, revenue: { value: '100.00', previous: '0.00', change: null }, orders: { value: 1, previous: 0, change: null } },
  ],
  summary: {
    total_customers: { value: 70, previous: 70, change: 0 },
    active_customers: { value: 46, previous: 48, change: -4.2 },
    new_customers: { value: 7, previous: 13, change: -46.2 },
    returning_customers: { value: 39, previous: 35, change: 11.4 },
  },
  meta: { ...meta, sort: 'revenue', limit: 10 },
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

mockComponent('~/components/charts/RevenueChart.client.vue', {
  props: ['metric'],
  setup: (props: { metric?: string }) => () => h('div', { 'data-chart': '', 'data-metric': props.metric }),
})
mockComponent('~/components/charts/StatusDonutChart.client.vue', {
  setup: (_props, { slots }) => () => h('div', slots.default?.()),
})

const period = { from: '2026-08-31', to: '2026-09-29' }

function rowOf(wrapper: { findAll: (selector: string) => Array<{ text: () => string }> }, label: string) {
  const row = wrapper.findAll('tbody tr').find(node => node.text().includes(label))

  if (!row) {
    throw new Error(`Row "${label}" not found`)
  }

  return row as unknown as { text: () => string, findAll: (selector: string) => Array<{ attributes: (name: string) => string | undefined, classes: () => string[] }> }
}

beforeEach(async () => {
  vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-29T15:00:00Z') })
  Object.values(repository).forEach(mock => mock.mockReset())
  repository.revenue.mockResolvedValue(revenue)
  repository.products.mockResolvedValue(products)
  repository.customers.mockResolvedValue(customers)
  repository.transactionStatus.mockResolvedValue(status)
  clearNuxtData()
  useAuthStore().setSession({ id: 'u1', name: 'Member', email: 'member@example.com' }, org)
  await useRouter().replace({ path: '/analytics/products', query: {} })
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useRankingQuery', () => {
  it('reads sort and limit from the URL, ignoring invalid values', async () => {
    const router = useRouter()
    const ranking = useRankingQuery(['revenue', 'units_sold'] as const, 'revenue')

    expect([ranking.sort.value, ranking.limit.value]).toEqual(['revenue', 10])

    await router.replace({ query: { sort: 'units_sold', limit: '25' } })
    expect([ranking.sort.value, ranking.limit.value]).toEqual(['units_sold', 25])

    await router.replace({ query: { sort: 'price', limit: '51' } })
    expect([ranking.sort.value, ranking.limit.value]).toEqual(['revenue', 10])

    await router.replace({ query: { limit: '0' } })
    expect(ranking.limit.value).toBe(10)
  })

  it('omits defaults and keeps the period in the URL', async () => {
    const router = useRouter()
    await router.replace({ query: { period: '90d' } })
    const ranking = useRankingQuery(['revenue', 'units_sold'] as const, 'revenue')

    await ranking.setSort('units_sold')
    await ranking.setLimit(50)
    expect(router.currentRoute.value.query).toEqual({ period: '90d', sort: 'units_sold', limit: '50' })

    await ranking.setSort('revenue')
    await ranking.setLimit(10)
    expect(router.currentRoute.value.query).toEqual({ period: '90d' })
  })
})

describe('analytics tabs', () => {
  it('carry only the period to the other tabs', async () => {
    const wrapper = await mountSuspended(AnalyticsPage, { route: '/analytics/products?period=90d&sort=units_sold&limit=25' })
    await flushPromises()

    const hrefs = wrapper.get('nav[aria-label="Seções de analytics"]').findAll('a').map(link => link.attributes('href'))
    expect(hrefs).toEqual([
      '/analytics/revenue?period=90d',
      '/analytics/products?period=90d',
      '/analytics/customers?period=90d',
      '/analytics/transactions?period=90d',
    ])
    wrapper.unmount()
  })
})

describe('revenue tab', () => {
  it('shows the summary and a table of buckets with partial periods marked', async () => {
    const wrapper = await mountSuspended(RevenuePage, { route: '/analytics/revenue?granularity=week' })
    await flushPromises()

    expect(repository.revenue).toHaveBeenCalledWith({ ...period, granularity: 'week' })
    expect(repository.revenue).toHaveBeenCalledTimes(1)
    expect(repository.products).not.toHaveBeenCalled()

    const text = wrapper.text()
    expect(text).toMatch(/Receita\s*R\$\s25\.143,60/)
    expect(text).toContain('Queda de 73,6% em relação ao período anterior.')
    expect(rowOf(wrapper, '31 ago – 06 set 2026').text()).toMatch(/R\$\s23\.869,20\s*22/)
    expect(text).toContain('28 – 29 set 2026 (parcial)')
    wrapper.unmount()
  })

  it('switches the chart metric without a new request', async () => {
    const wrapper = await mountSuspended(RevenuePage, { route: '/analytics/revenue' })
    await flushPromises()

    await wrapper.findAll('button').find(button => button.text() === 'Pedidos')!.trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-chart]').attributes('data-metric')).toBe('orders')
    expect(repository.revenue).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})

describe('products tab', () => {
  it('requests the ranking with sort and limit from the URL', async () => {
    const wrapper = await mountSuspended(ProductsPage, { route: '/analytics/products?sort=units_sold&limit=25' })
    await flushPromises()

    expect(repository.products).toHaveBeenCalledWith({ ...period, sort: 'units_sold', limit: 25 })
    wrapper.unmount()
  })

  it('updates the URL and refetches when the sort changes', async () => {
    const wrapper = await mountSuspended(ProductsPage, { route: '/analytics/products' })
    await flushPromises()

    await wrapper.findAll('button').find(button => button.text() === 'Unidades')!.trigger('click')

    await vi.waitFor(() => expect(repository.products).toHaveBeenLastCalledWith({ ...period, sort: 'units_sold', limit: 10 }))
    expect(useRouter().currentRoute.value.query).toEqual({ sort: 'units_sold' })
    wrapper.unmount()
  })

  it('shows the summary and flags inactive and removed products', async () => {
    const wrapper = await mountSuspended(ProductsPage, { route: '/analytics/products' })
    await flushPromises()

    const text = wrapper.text()
    expect(text).toMatch(/Unidades vendidas\s*288/)
    expect(text).toMatch(/Produtos vendidos\s*34/)

    const links = wrapper.findAll('tbody a').map(link => link.attributes('href'))
    expect(links).toEqual(['/products/p1', '/products/p2'])
    expect(rowOf(wrapper, 'Monitor antigo').text()).toContain('Inativo')
    expect(rowOf(wrapper, 'Monitor antigo').text()).toContain('Novo')
    expect(rowOf(wrapper, 'Mesa descontinuada').text()).toContain('Removido')
    wrapper.unmount()
  })

  it('shows an empty ranking for a period without sales', async () => {
    repository.products.mockResolvedValue({ ...products, data: [] })

    const wrapper = await mountSuspended(ProductsPage, { route: '/analytics/products' })
    await flushPromises()

    expect(wrapper.text()).toContain('Nenhum produto vendido no período')
    wrapper.unmount()
  })
})

describe('customers tab', () => {
  it('splits active customers into new and returning', async () => {
    const wrapper = await mountSuspended(CustomersPage, { route: '/analytics/customers' })
    await flushPromises()

    expect(repository.customers).toHaveBeenCalledWith({ ...period, sort: 'revenue', limit: 10 })
    expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe('7 novos (15,2%) e 39 recorrentes (84,8%)')
    expect(wrapper.text()).toMatch(/Base de clientes\s*70/)
    expect(wrapper.findAll('tbody a').map(link => link.attributes('href'))).toEqual(['/customers/c1'])
    expect(rowOf(wrapper, 'Ana Removida').text()).toContain('Removido')
    wrapper.unmount()
  })

  it('hides the split without active customers', async () => {
    const zero = { value: 0, previous: 0, change: 0 }
    repository.customers.mockResolvedValue({
      ...customers,
      data: [],
      summary: { ...customers.summary, active_customers: zero, new_customers: zero, returning_customers: zero },
    })

    const wrapper = await mountSuspended(CustomersPage, { route: '/analytics/customers' })
    await flushPromises()

    expect(wrapper.text()).not.toContain('Novos e recorrentes')
    expect(wrapper.text()).toContain('Nenhum cliente comprou no período')
    wrapper.unmount()
  })
})

describe('transactions tab', () => {
  it('explains the exception to the paid-only rule and inverts polarity for refunds and cancellations', async () => {
    const wrapper = await mountSuspended(TransactionsPage, { route: '/analytics/transactions' })
    await flushPromises()

    expect(repository.transactionStatus).toHaveBeenCalledWith(period)
    expect(wrapper.text()).toContain('Esta aba considera todos os status')

    // Refunds up 40%: an increase, shown as bad news.
    const refunded = rowOf(wrapper, 'Reembolsado')
    expect(refunded.text()).toContain('Aumento de 40,0%')
    expect(refunded.findAll('[title^="Aumento de 40,0%"]')[0]!.classes()).toContain('bg-danger-soft')

    // Cancellations down 40%: a decrease, shown as good news.
    const canceled = rowOf(wrapper, 'Cancelado')
    expect(canceled.findAll('[title^="Queda de 40,0%"]')[0]!.classes()).toContain('bg-success-soft')
    wrapper.unmount()
  })

  it('shows "—" for shares when the period has no transactions', async () => {
    const zero = { value: 0, previous: 0, change: 0 }
    repository.transactionStatus.mockResolvedValue({
      ...status,
      data: status.data.map(row => ({ ...row, orders: zero, revenue: { value: '0.00', previous: '0.00', change: 0 }, percentage: { value: null, previous: null, change: null } })),
    })

    const wrapper = await mountSuspended(TransactionsPage, { route: '/analytics/transactions' })
    await flushPromises()

    expect(rowOf(wrapper, 'Pago').text()).toContain('—')
    expect(wrapper.text()).toContain('Nenhuma transação no período')
    wrapper.unmount()
  })
})
