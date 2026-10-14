import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import type { ConnectionStatus } from 'laravel-echo'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import type { RealtimeConfig } from '~/types/realtime'
import { formatMoney } from '~/utils/format'
import RealtimeIndicator from '~/components/layout/RealtimeIndicator.vue'
import { LIVE_REFRESH_DEBOUNCE_MS, useOrganizationChannel } from '~/composables/useOrganizationChannel'

type Listener = (payload: unknown) => void

/** Just enough of Echo: private channels with listeners, `leave`, the connection status. */
function fakeEcho() {
  const channels = new Map<string, Map<string, Listener>>()
  let onStatus: ((status: ConnectionStatus) => void) | null = null

  const instance = {
    private: vi.fn((name: string) => {
      const listeners = new Map<string, Listener>()
      channels.set(name, listeners)
      const channel = {
        listen: (event: string, callback: Listener) => {
          listeners.set(event, callback)

          return channel
        },
      }

      return channel
    }),
    leave: vi.fn((name: string) => channels.delete(name)),
    disconnect: vi.fn(),
    connector: {
      onConnectionChange: vi.fn((callback: (status: ConnectionStatus) => void) => {
        onStatus = callback

        return () => {
          onStatus = null
        }
      }),
    },
  }

  return {
    instance,
    channels,
    emit: (channel: string, event: string, payload: unknown) => channels.get(channel)?.get(event)?.(payload),
    setStatus: (status: ConnectionStatus) => onStatus?.(status),
  }
}

const { repository, createEchoMock, refreshMock } = vi.hoisted(() => ({
  repository: { config: vi.fn(), authorize: vi.fn() },
  createEchoMock: vi.fn(),
  refreshMock: vi.fn(),
}))

vi.mock('~/repositories/realtimeRepository', () => ({ useRealtimeRepository: () => repository }))
vi.mock('~/utils/echo', () => ({ createEcho: createEchoMock }))
mockNuxtImport('refreshNuxtData', () => refreshMock)

const user = { id: 'u1', name: 'Owner', email: 'owner@example.com' }
const orgA: Organization = { id: '0199a000-0000-7000-8000-00000000000a', name: 'Loja A', slug: 'a', currency: 'BRL', timezone: 'America/Sao_Paulo', insights: { available: false, enabled: false }, role: 'owner' }
const orgB: Organization = { id: '0199a000-0000-7000-8000-00000000000b', name: 'Loja B', slug: 'b', currency: 'USD', timezone: 'UTC', insights: { available: false, enabled: false }, role: 'member' }

const enabled: RealtimeConfig = { enabled: true, key: 'public-key', host: 'api.example.test', port: 443, scheme: 'https', auth_endpoint: '/api/v1/broadcasting/auth' }

const sale = { id: 't1', status: 'paid', total_amount: '1234.50', currency: 'BRL', occurred_at: '2026-10-13T20:00:00.000000Z' }

const Harness = defineComponent({
  setup() {
    useOrganizationChannel()

    return () => h(RealtimeIndicator)
  },
})

let echo: ReturnType<typeof fakeEcho>
let wrapper: VueWrapper | null = null

async function mountHarness() {
  wrapper = await mountSuspended(Harness)
  await flushPromises()

  return wrapper
}

describe('useOrganizationChannel', () => {
  beforeEach(() => {
    echo = fakeEcho()
    repository.config.mockReset().mockResolvedValue(enabled)
    repository.authorize.mockReset()
    createEchoMock.mockReset().mockResolvedValue(echo.instance)
    refreshMock.mockReset()
    useToast().toasts.value = []
    useAuthStore().setSession(user, orgA, [orgA, orgB])
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.useRealTimers()
    clearNuxtData()
  })

  it('subscribes to the organization channel and shows the live indicator', async () => {
    const view = await mountHarness()

    expect(createEchoMock).toHaveBeenCalledWith(enabled, expect.any(Function))
    expect(echo.instance.private).toHaveBeenCalledWith(`organization.${orgA.id}`)
    expect(view.text()).toContain('Conectando')

    echo.setStatus('connected')
    await flushPromises()
    expect(view.text()).toContain('Ao vivo')

    echo.setStatus('reconnecting')
    await flushPromises()
    expect(view.text()).toContain('Reconectando')
  })

  it('authorizes the channel through the API client', async () => {
    repository.authorize.mockResolvedValue({ auth: 'public-key:signature' })
    await mountHarness()

    const authorize = createEchoMock.mock.calls[0]![1] as (socketId: string, channel: string) => Promise<unknown>
    await expect(authorize('1.2', `private-organization.${orgA.id}`)).resolves.toEqual({ auth: 'public-key:signature' })
    expect(repository.authorize).toHaveBeenCalledWith('1.2', `private-organization.${orgA.id}`)
  })

  it('refreshes the live screens of the organization once per burst', async () => {
    const data = useNuxtApp().payload.data
    data[`dashboard:kpis:${orgA.id}`] = {}
    data[`transactions:list:${orgA.id}`] = {}
    data[`insights:period-summary:${orgA.id}:2026-09-01:2026-09-30`] = {}
    data[`dashboard:kpis:${orgB.id}`] = {}

    await mountHarness()
    vi.useFakeTimers()

    echo.emit(`organization.${orgA.id}`, '.transaction.recorded', sale)
    echo.emit(`organization.${orgA.id}`, '.stock.changed', { product_id: 'p1', sku: 'PB-1', name: 'Cadeira', tracked: true, quantity: 5, min_quantity: 2, status: 'ok', previous_status: 'ok' })
    vi.advanceTimersByTime(LIVE_REFRESH_DEBOUNCE_MS - 1)
    expect(refreshMock).not.toHaveBeenCalled()

    echo.emit(`organization.${orgA.id}`, '.transaction.status-changed', { ...sale, from: 'pending', to: 'paid' })
    vi.advanceTimersByTime(LIVE_REFRESH_DEBOUNCE_MS)

    expect(refreshMock).toHaveBeenCalledOnce()
    expect(refreshMock).toHaveBeenCalledWith([`dashboard:kpis:${orgA.id}`, `transactions:list:${orgA.id}`])
  })

  it('catches up once the connection comes back after a drop', async () => {
    useNuxtApp().payload.data[`dashboard:kpis:${orgA.id}`] = {}
    await mountHarness()
    vi.useFakeTimers()

    echo.setStatus('connected')
    vi.advanceTimersByTime(LIVE_REFRESH_DEBOUNCE_MS)
    expect(refreshMock).not.toHaveBeenCalled()

    echo.setStatus('reconnecting')
    echo.setStatus('connected')
    vi.advanceTimersByTime(LIVE_REFRESH_DEBOUNCE_MS)

    expect(refreshMock).toHaveBeenCalledExactlyOnceWith([`dashboard:kpis:${orgA.id}`])
  })

  it('toasts new paid sales', async () => {
    await mountHarness()

    echo.emit(`organization.${orgA.id}`, '.transaction.recorded', sale)
    echo.emit(`organization.${orgA.id}`, '.transaction.recorded', { ...sale, id: 't2', status: 'pending' })

    expect(useToast().toasts.value.map(toast => toast.title)).toEqual([`Nova venda ${formatMoney('1234.50', 'BRL')}`])
  })

  it('moves the subscription when the organization changes', async () => {
    await mountHarness()

    useAuthStore().setSession(user, orgB, [orgA, orgB])
    await flushPromises()

    expect(echo.instance.leave).toHaveBeenCalledWith(`organization.${orgA.id}`)
    expect(echo.instance.private).toHaveBeenLastCalledWith(`organization.${orgB.id}`)
    expect(createEchoMock).toHaveBeenCalledOnce()
  })

  it('stays off when the API has no realtime server', async () => {
    repository.config.mockResolvedValue({ enabled: false })

    const view = await mountHarness()

    expect(createEchoMock).not.toHaveBeenCalled()
    expect(view.text()).toBe('')
  })

  it('stays off when the realtime config cannot be loaded', async () => {
    repository.config.mockRejectedValue(new Error('Network error'))

    const view = await mountHarness()

    expect(createEchoMock).not.toHaveBeenCalled()
    expect(view.text()).toBe('')
  })

  it('disconnects when the layout goes away', async () => {
    const view = await mountHarness()

    view.unmount()
    wrapper = null
    await flushPromises()

    expect(echo.instance.disconnect).toHaveBeenCalledOnce()
    expect(useRealtimeStatus().value).toBe('off')
  })
})
