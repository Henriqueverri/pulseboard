import type { ChannelAuthorizer, OrganizationEcho } from '~/utils/echo'
import type {
  RealtimeStatus,
  StockChangedEvent,
  TransactionRecordedEvent,
  TransactionStatusChangedEvent,
} from '~/types/realtime'
import type { LiveNotice } from '~/utils/realtime'
import { useRealtimeRepository } from '~/repositories/realtimeRepository'
import { createEcho } from '~/utils/echo'

/** A POS sale moves the transaction and several stock rows: refetch once, after the burst. */
export const LIVE_REFRESH_DEBOUNCE_MS = 1000

export function useRealtimeStatus() {
  return useState<RealtimeStatus>('realtime:status', () => 'off')
}

/**
 * Subscribes to `private-organization.{id}` of the current organization and keeps the open
 * screens fresh: dashboard, transactions, analytics, products and customers. Mounted once by the
 * default layout; switching organization moves the subscription, leaving the layout disconnects.
 */
export function useOrganizationChannel() {
  const auth = useAuthStore()
  const status = useRealtimeStatus()
  const toast = useToast()
  const nuxtApp = useNuxtApp()
  const repository = useRealtimeRepository()

  let echo: Promise<OrganizationEcho | null> | null = null
  let channel: string | null = null
  let refreshTimer: ReturnType<typeof setTimeout> | null = null
  let stopStatus: (() => void) | null = null
  let disposed = false

  const authorize: ChannelAuthorizer = (socketId, channelName) => repository.authorize(socketId, channelName)

  function connect(): Promise<OrganizationEcho | null> {
    echo ??= (async () => {
      const config = await repository.config()
      if (!config.enabled || disposed) {
        return null
      }

      status.value = 'connecting'
      const instance = await createEcho(config, authorize)
      let dropped = false
      stopStatus = instance.connector.onConnectionChange((value) => {
        const next = toRealtimeStatus(value)
        dropped ||= status.value === 'live' && next === 'offline'

        // Events sent while the socket was down are gone: catch up once it is back.
        if (dropped && next === 'live' && auth.organization) {
          dropped = false
          scheduleRefresh(auth.organization.id)
        }
        status.value = next
      })

      return instance
    })().catch(() => {
      // No realtime (API unreachable, library failed to load): the screens still work; retry later.
      echo = null
      status.value = 'off'

      return null
    })

    return echo
  }

  function scheduleRefresh(organizationId: string) {
    if (refreshTimer) {
      clearTimeout(refreshTimer)
    }

    refreshTimer = setTimeout(() => {
      refreshTimer = null
      const keys = Object.keys(nuxtApp.payload.data).filter(key => isLiveDataKey(key, organizationId))
      if (keys.length > 0) {
        void refreshNuxtData(keys)
      }
    }, LIVE_REFRESH_DEBOUNCE_MS)
  }

  function handle(organizationId: string, notice: LiveNotice | null) {
    if (auth.organization?.id !== organizationId) {
      return
    }

    if (notice) {
      toast.push({ title: notice.title, tone: notice.tone })
    }
    scheduleRefresh(organizationId)
  }

  async function follow(organizationId: string | undefined) {
    const instance = organizationId ? await connect() : await echo

    if (disposed || auth.organization?.id !== organizationId) {
      return
    }

    if (channel) {
      instance?.leave(channel)
      channel = null
    }

    if (!instance || !organizationId) {
      return
    }

    channel = `organization.${organizationId}`
    instance.private(channel)
      .listen('.transaction.recorded', (event: TransactionRecordedEvent) =>
        handle(organizationId, transactionRecordedNotice(event)))
      .listen('.transaction.status-changed', (event: TransactionStatusChangedEvent) =>
        handle(organizationId, transactionStatusChangedNotice(event)))
      .listen('.stock.changed', (event: StockChangedEvent) =>
        handle(organizationId, stockChangedNotice(event)))
  }

  watch(() => auth.organization?.id, id => void follow(id), { immediate: true })

  onScopeDispose(() => {
    disposed = true
    if (refreshTimer) {
      clearTimeout(refreshTimer)
    }
    stopStatus?.()
    void echo?.then(instance => instance?.disconnect())
    echo = null
    status.value = 'off'
  })

  return { status }
}
