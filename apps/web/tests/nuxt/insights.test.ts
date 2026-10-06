import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockComponent, mountSuspended } from '@nuxt/test-utils/runtime'
import { defineComponent, h } from 'vue'
import { flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { useAuthStore } from '~/stores/auth'
import type { Organization, OrganizationRole } from '~/types/auth'
import type { PeriodSummaryResponse } from '~/types/insights'
import { ApiError } from '~/utils/api-error'
import InsightsCard from '~/components/insights/InsightsCard.vue'
import DashboardPage from '~/pages/dashboard.vue'
import InsightsSettingsPage from '~/pages/settings/insights.vue'
import { clickButton } from '../support/dom'

const repository = vi.hoisted(() => ({
  periodSummary: vi.fn(),
  generatePeriodSummary: vi.fn(),
  updateOptIn: vi.fn(),
}))

vi.mock('~/repositories/insightsRepository', () => ({
  useInsightsRepository: () => repository,
}))

const analytics = vi.hoisted(() => ({
  dashboard: vi.fn(),
  revenue: vi.fn(),
  transactionStatus: vi.fn(),
  products: vi.fn(),
  customers: vi.fn(),
}))

vi.mock('~/repositories/analyticsRepository', () => ({
  useAnalyticsRepository: () => analytics,
}))

mockComponent('~/components/charts/RevenueChart.client.vue', { setup: () => () => h('div') })
mockComponent('~/components/charts/StatusDonutChart.client.vue', { setup: () => () => h('div') })

const user = { id: 'u1', name: 'Owner', email: 'owner@example.com' }

function org(role: OrganizationRole, insights: Organization['insights'] = { available: true, enabled: true }): Organization {
  return {
    id: '0199a000-0000-7000-8000-00000000000a', name: 'Loja', slug: 'loja', currency: 'BRL', timezone: 'America/Sao_Paulo', insights, role,
  }
}

const meta = {
  period: { from: '2026-08-31', to: '2026-09-29', days: 30 },
  previous_period: { from: '2026-08-01', to: '2026-08-30', days: 30 },
  timezone: 'America/Sao_Paulo',
  currency: 'BRL',
}

// Shape of a real `PeriodSummaryResource` response.
const summary: PeriodSummaryResponse = {
  data: {
    headline: 'Receita cresce com ticket médio maior, apesar de menos pedidos',
    overview: 'A receita do período subiu em relação ao anterior, puxada pelo ticket médio, enquanto o volume de pedidos caiu.',
    findings: [
      {
        kind: 'positive',
        title: 'Ticket médio em alta',
        explanation: 'Cada pedido pago rendeu mais do que no período anterior.',
        destination: 'analytics.products',
        evidence: [
          { ref: 'kpi.revenue', label: 'Receita', format: 'money', polarity: 'positive', destination: 'analytics.revenue', value: '96962.20', previous: '90308.20', change: 7.4 },
          { ref: 'kpi.orders', label: 'Pedidos pagos', format: 'count', polarity: 'positive', destination: 'analytics.revenue', value: 121, previous: 149, change: -18.8 },
        ],
      },
      {
        kind: 'attention',
        title: 'Mais reembolsos',
        explanation: 'Os reembolsos aumentaram e merecem uma olhada nas transações.',
        destination: 'transactions.refunded',
        evidence: [
          { ref: 'status.refunded', label: 'Reembolsadas', format: 'count', polarity: 'negative', destination: 'transactions.refunded', value: 14, previous: 10, change: 40 },
        ],
      },
    ],
    attention_points: [
      {
        text: 'Verifique os produtos com mais reembolsos no período.',
        evidence: [
          { ref: 'product.1', label: 'Cadeira ergonômica Pro', format: 'money', polarity: 'positive', destination: 'analytics.products', value: '20148.70', previous: '9299.40', change: 116.7 },
        ],
      },
    ],
    caveats: ['partial_period', 'status_is_current'],
  },
  meta: {
    ...meta,
    insight: { generated_at: '2026-09-29T14:32:00Z', model: 'gpt-4.1-mini', prompt_version: 'period_summary.v1', cached: false },
  },
}

const Host = defineComponent({
  setup() {
    const period = useReportingPeriod()

    return () => h(InsightsCard, { period })
  },
})

async function mountCard(route = '/dashboard') {
  const wrapper = await mountSuspended(Host, { route })
  await flushPromises()

  return wrapper
}

function text(wrapper: VueWrapper) {
  return wrapper.text().replace(/\s+/g, ' ')
}

function button(wrapper: VueWrapper, label: string) {
  const found = wrapper.findAll('button').find(node => node.text().includes(label))
  if (!found) {
    throw new Error(`No button "${label}"`)
  }

  return found
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })

  return { promise, resolve }
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-29T15:00:00Z') })
  Object.values(repository).forEach(mock => mock.mockReset())
  Object.values(analytics).forEach(mock => mock.mockReset().mockReturnValue(new Promise(() => {})))
  repository.periodSummary.mockResolvedValue({ data: null })
  document.body.innerHTML = ''
  clearNuxtData()
  useAuthStore().setSession(user, org('member'))
})

afterEach(() => {
  vi.useRealTimers()
})

describe('insights on the dashboard', () => {
  it('hides the card when AI is globally unavailable', async () => {
    useAuthStore().setSession(user, org('owner', { available: false, enabled: true }))

    const wrapper = await mountSuspended(DashboardPage, { route: '/dashboard' })
    await flushPromises()

    expect(wrapper.text()).not.toContain('Insights do período')
    expect(repository.periodSummary).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows the card below the KPIs when available', async () => {
    const wrapper = await mountSuspended(DashboardPage, { route: '/dashboard' })
    await flushPromises()

    expect(wrapper.text()).toContain('Insights do período')
    expect(repository.periodSummary).toHaveBeenCalledWith({ from: '2026-08-31', to: '2026-09-29' })
    wrapper.unmount()
  })
})

describe('insights card', () => {
  it('explains the opt-in to members without requesting the API', async () => {
    useAuthStore().setSession(user, org('member', { available: true, enabled: false }))

    const wrapper = await mountCard()

    expect(text(wrapper)).toContain('Insights não ativados')
    expect(text(wrapper)).toContain('Um owner da organização pode ativar os insights em Configurações.')
    expect(wrapper.find('a[href="/settings/insights"]').exists()).toBe(false)
    expect(repository.periodSummary).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('links owners to the insights settings', async () => {
    useAuthStore().setSession(user, org('owner', { available: true, enabled: false }))

    const wrapper = await mountCard()

    expect(wrapper.find('a[href="/settings/insights"]').text()).toContain('Configurar insights')
    wrapper.unmount()
  })

  it('offers to generate when the period has no summary yet, with the privacy notice', async () => {
    const wrapper = await mountCard('/dashboard?period=7d')

    expect(repository.periodSummary).toHaveBeenCalledWith({ from: '2026-09-23', to: '2026-09-29' })
    expect(repository.generatePeriodSummary).not.toHaveBeenCalled()
    expect(text(wrapper)).toContain('Gerar insights do período')
    expect(text(wrapper)).toContain('últimos 7 dias')
    expect(text(wrapper)).toContain('sem nomes ou e-mails de clientes')
    expect(text(wrapper)).not.toContain('Gerado por IA')
    wrapper.unmount()
  })

  it('shows a skeleton while the cached summary loads', async () => {
    repository.periodSummary.mockReturnValue(new Promise(() => {}))

    const wrapper = await mountCard()

    expect(wrapper.find('[aria-busy="true"]').text()).toContain('Carregando insights do período')
    wrapper.unmount()
  })

  it('shows a cached summary without generating', async () => {
    repository.periodSummary.mockResolvedValue({ ...summary, meta: { ...summary.meta, insight: { ...summary.meta.insight, cached: true } } })

    const wrapper = await mountCard()

    expect(text(wrapper)).toContain('Receita cresce com ticket médio maior')
    expect(repository.generatePeriodSummary).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('generates the summary, announces progress and moves focus to the card title', async () => {
    const pending = deferred<PeriodSummaryResponse>()
    repository.generatePeriodSummary.mockReturnValue(pending.promise)

    const wrapper = await mountCard('/dashboard?period=7d')
    document.body.appendChild(wrapper.element)

    await button(wrapper, 'Gerar insights do período').trigger('click')

    expect(repository.generatePeriodSummary).toHaveBeenCalledWith({ from: '2026-09-23', to: '2026-09-29' })
    expect(text(wrapper)).toContain('Analisando o período…')
    expect(wrapper.find('[aria-live="polite"]').attributes('aria-busy')).toBe('true')

    pending.resolve(summary)
    await flushPromises()

    expect(text(wrapper)).toContain('Receita cresce com ticket médio maior')
    expect(document.activeElement?.textContent).toContain('Insights do período')
    wrapper.unmount()
  })

  it('renders the findings with server numbers, kind labels, links and caveats', async () => {
    repository.periodSummary.mockResolvedValue(summary)

    const wrapper = await mountCard('/dashboard?period=7d')
    const content = text(wrapper)

    expect(content).toContain('Gerado por IA')
    expect(content).toMatch(/Ponto positivo:\s*Ticket médio em alta/)
    expect(content).toMatch(/Atenção:\s*Mais reembolsos/)
    expect(content).toMatch(/Receita\s*R\$\s96\.962,20/)
    expect(content).toContain('Aumento de 7,4% em relação ao período anterior.')
    expect(content).toMatch(/Pedidos pagos\s*121/)
    expect(content).toContain('Queda de 18,8% em relação ao período anterior.')
    expect(content).toContain('Pontos de atenção')
    expect(content).toMatch(/Cadeira ergonômica Pro\s*R\$\s20\.148,70/)
    expect(content).toContain('O período termina hoje ou depois')
    expect(content).toContain('Os status são os atuais')
    expect(content).toContain('Gerado por IA (gpt-4.1-mini) em 29/09/2026 11:32')

    const products = wrapper.find('a[aria-label="Ver Analytics de produtos, últimos 7 dias"]')
    expect(products.attributes('href')).toBe('/analytics/products?period=7d')

    const refunded = wrapper.find('a[aria-label="Ver Transações reembolsadas, últimos 7 dias"]')
    expect(refunded.attributes('href')).toBe('/transactions?status=refunded&from=2026-09-23&to=2026-09-29')
    wrapper.unmount()
  })

  it('never links a destination outside the enum', async () => {
    const tampered = structuredClone(summary) as { data: { findings: Array<{ destination: string }> } }
    tampered.data.findings[0]!.destination = 'https://evil.example.com'
    repository.periodSummary.mockResolvedValue(tampered)

    const wrapper = await mountCard()

    expect(wrapper.findAll('a').map(link => link.attributes('href'))).not.toContain('https://evil.example.com')
    expect(text(wrapper)).toContain('Ticket médio em alta')
    wrapper.unmount()
  })

  it('shows an invalid output with the request id and generates again on retry', async () => {
    repository.generatePeriodSummary
      .mockRejectedValueOnce(new ApiError(502, { message: 'The AI answer could not be validated.', code: 'ai_invalid_output' }, undefined, { requestId: 'req-42' }))
      .mockResolvedValueOnce(summary)

    const wrapper = await mountCard()
    await button(wrapper, 'Gerar insights do período').trigger('click')
    await flushPromises()

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('A resposta da IA não passou na validação e foi descartada.')
    expect(alert.text()).toContain('req-42')

    await alert.get('button').trigger('click')
    await flushPromises()

    expect(repository.generatePeriodSummary).toHaveBeenCalledTimes(2)
    expect(repository.periodSummary).toHaveBeenCalledTimes(1)
    expect(text(wrapper)).toContain('Receita cresce com ticket médio maior')
    wrapper.unmount()
  })

  it.each([
    ['ai_provider_unavailable', 503, 'O serviço de IA está indisponível'],
    ['ai_timeout', 504, 'A IA demorou demais para responder'],
  ])('offers a retry for %s', async (code, status, message) => {
    repository.generatePeriodSummary.mockRejectedValueOnce(new ApiError(status, { message: 'x', code }))

    const wrapper = await mountCard()
    await button(wrapper, 'Gerar insights do período').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain(message)
    expect(button(wrapper, 'Tentar novamente').exists()).toBe(true)
    wrapper.unmount()
  })

  it('reloads the cached summary when the initial request fails', async () => {
    repository.periodSummary
      .mockRejectedValueOnce(new ApiError(500, { message: 'Server Error' }))
      .mockResolvedValueOnce(summary)

    const wrapper = await mountCard()
    expect(wrapper.get('[role="alert"]').text()).toContain('O servidor encontrou um erro')

    await button(wrapper, 'Tentar novamente').trigger('click')
    await flushPromises()

    expect(repository.periodSummary).toHaveBeenCalledTimes(2)
    expect(repository.generatePeriodSummary).not.toHaveBeenCalled()
    expect(text(wrapper)).toContain('Receita cresce com ticket médio maior')
    wrapper.unmount()
  })

  it('tells when the quota frees up, without a retry button', async () => {
    repository.generatePeriodSummary.mockRejectedValueOnce(
      new ApiError(429, { message: 'The insights quota has been reached.', code: 'ai_quota_exceeded' }, undefined, { retryAfter: 9 * 3600 }),
    )

    const wrapper = await mountCard()
    await button(wrapper, 'Gerar insights do período').trigger('click')
    await flushPromises()

    expect(text(wrapper)).toContain('Cota diária de insights atingida')
    expect(text(wrapper)).toContain('a partir das 21:00')
    expect(wrapper.findAll('button').some(node => node.text().includes('Tentar novamente'))).toBe(false)
    wrapper.unmount()
  })

  it('explains that insights are unavailable when the kill switch answers ai_disabled', async () => {
    repository.generatePeriodSummary.mockRejectedValueOnce(new ApiError(503, { message: 'x', code: 'ai_disabled' }))

    const wrapper = await mountCard()
    await button(wrapper, 'Gerar insights do período').trigger('click')
    await flushPromises()

    expect(text(wrapper)).toContain('Insights indisponíveis no momento')
    expect(wrapper.findAll('button').length).toBe(0)
    wrapper.unmount()
  })

  it('shows the opt-in state when the API answers ai_not_enabled', async () => {
    repository.periodSummary.mockRejectedValueOnce(new ApiError(403, { message: 'x', code: 'ai_not_enabled' }))

    const wrapper = await mountCard()

    expect(text(wrapper)).toContain('Insights não ativados')
    wrapper.unmount()
  })

  it('drops the shown summary and loads the new period when the period changes', async () => {
    repository.periodSummary.mockResolvedValueOnce(summary).mockResolvedValueOnce({ data: null })

    const wrapper = await mountCard()
    expect(text(wrapper)).toContain('Receita cresce com ticket médio maior')

    await useRouter().replace({ path: '/dashboard', query: { period: '7d' } })
    await flushPromises()

    expect(repository.periodSummary).toHaveBeenLastCalledWith({ from: '2026-09-23', to: '2026-09-29' })
    expect(text(wrapper)).not.toContain('Receita cresce com ticket médio maior')
    expect(text(wrapper)).toContain('Gerar insights do período')
    wrapper.unmount()
  })

  it('discards a generation that finishes after the period changed', async () => {
    const pending = deferred<PeriodSummaryResponse>()
    repository.generatePeriodSummary.mockReturnValue(pending.promise)

    const wrapper = await mountCard()
    await button(wrapper, 'Gerar insights do período').trigger('click')

    await useRouter().replace({ path: '/dashboard', query: { period: '7d' } })
    await flushPromises()
    expect(text(wrapper)).toContain('Gerar insights do período')

    pending.resolve(summary)
    await flushPromises()

    expect(text(wrapper)).not.toContain('Receita cresce com ticket médio maior')
    wrapper.unmount()
  })
})

describe('insights settings page', () => {
  it('lets an owner enable insights and updates the session organization', async () => {
    useAuthStore().setSession(user, org('owner', { available: true, enabled: false }))
    repository.updateOptIn.mockResolvedValue(org('owner', { available: true, enabled: true }))

    const wrapper = await mountSuspended(InsightsSettingsPage, { route: '/settings/insights' })
    await flushPromises()

    expect(text(wrapper)).toContain('Nunca enviado')
    expect(text(wrapper)).toContain('Nomes e e-mails de clientes')

    await wrapper.get('[role="switch"]').trigger('click')
    await flushPromises()

    expect(repository.updateOptIn).toHaveBeenCalledWith(true)
    expect(useAuthStore().organization?.insights.enabled).toBe(true)
    expect(useAuthStore().organizations[0]?.insights.enabled).toBe(true)
    expect(useAuthStore().organization?.role).toBe('owner')
    wrapper.unmount()
  })

  it('asks for confirmation before disabling, since cached summaries are deleted', async () => {
    useAuthStore().setSession(user, org('owner'))
    repository.updateOptIn.mockResolvedValue(org('owner', { available: true, enabled: false }))

    const wrapper = await mountSuspended(InsightsSettingsPage, { route: '/settings/insights' })
    await flushPromises()

    await wrapper.get('[role="switch"]').trigger('click')
    await flushPromises()

    expect(repository.updateOptIn).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('os resumos já gerados desta organização são apagados')

    await clickButton('Desativar insights')

    expect(repository.updateOptIn).toHaveBeenCalledWith(false)
    expect(useAuthStore().organization?.insights.enabled).toBe(false)
    wrapper.unmount()
  })

  it('keeps the setting read-only for members', async () => {
    useAuthStore().setSession(user, org('member'))

    const wrapper = await mountSuspended(InsightsSettingsPage, { route: '/settings/insights' })
    await flushPromises()

    expect(text(wrapper)).toContain('somente owners ativam ou desativam os insights')
    expect(wrapper.get('[role="switch"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('blocks enabling while AI is globally off, but still allows opting out', async () => {
    useAuthStore().setSession(user, org('owner', { available: false, enabled: false }))

    const off = await mountSuspended(InsightsSettingsPage, { route: '/settings/insights' })
    await flushPromises()

    expect(text(off)).toContain('A geração por IA está desligada neste ambiente.')
    expect(off.get('[role="switch"]').attributes('disabled')).toBeDefined()
    off.unmount()

    useAuthStore().setSession(user, org('owner', { available: false, enabled: true }))

    const optedIn = await mountSuspended(InsightsSettingsPage, { route: '/settings/insights' })
    await flushPromises()

    expect(optedIn.get('[role="switch"]').attributes('disabled')).toBeUndefined()
    optedIn.unmount()
  })

  it('reports a failed update without changing the session', async () => {
    useAuthStore().setSession(user, org('owner', { available: true, enabled: false }))
    repository.updateOptIn.mockRejectedValue(new ApiError(503, { message: 'x', code: 'ai_disabled' }))

    const wrapper = await mountSuspended(InsightsSettingsPage, { route: '/settings/insights' })
    await flushPromises()

    await wrapper.get('[role="switch"]').trigger('click')
    await flushPromises()

    expect(useToast().toasts.value.at(-1)).toMatchObject({
      tone: 'danger',
      description: 'Os insights estão indisponíveis no momento.',
    })
    expect(useAuthStore().organization?.insights.enabled).toBe(false)
    wrapper.unmount()
  })
})
