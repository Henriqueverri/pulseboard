import { useInsightsRepository } from '~/repositories/insightsRepository'
import type { PeriodSummaryResponse } from '~/types/insights'
import type { ReportingPeriod } from './useReportingPeriod'

/** `available`: AI_ENABLED (global); `enabled`: available and the organization opted in. */
export function useInsightsAvailability() {
  const auth = useAuthStore()

  const available = computed(() => auth.organization?.insights.available ?? false)
  const enabled = computed(() => available.value && (auth.organization?.insights.enabled ?? false))

  return { available, enabled }
}

/**
 * Summary of the reporting period. Loading uses `GET`, which never calls the AI provider:
 * it answers the cached summary or `data: null`. `generate()` posts the same period.
 *
 * The key holds the period, so a new period starts empty instead of showing the previous
 * summary. A generation that finishes after the period changed is discarded.
 */
export function usePeriodSummary(period: ReportingPeriod) {
  const repository = useInsightsRepository()
  const auth = useAuthStore()
  const { enabled } = useInsightsAvailability()

  const key = () => `insights:period-summary:${auth.organization?.id ?? 'none'}:${period.params.value.from}:${period.params.value.to}`

  const summary = useAsyncData<PeriodSummaryResponse | null>(
    key,
    () => (enabled.value ? repository.periodSummary(period.params.value) : Promise.resolve(null)),
    { watch: [enabled] },
  )

  const generatingKey = ref<string | null>(null)
  const failure = shallowRef<{ key: string, error: unknown } | null>(null)

  const generating = computed(() => generatingKey.value === key())
  const generateError = computed(() => (failure.value?.key === key() ? failure.value.error : null))

  async function generate() {
    const requestKey = key()

    if (generatingKey.value === requestKey) {
      return
    }

    generatingKey.value = requestKey
    failure.value = null

    try {
      const response = await repository.generatePeriodSummary(period.params.value)

      if (key() === requestKey) {
        summary.data.value = response
      }
    }
    catch (error) {
      failure.value = { key: requestKey, error }
    }
    finally {
      if (generatingKey.value === requestKey) {
        generatingKey.value = null
      }
    }
  }

  return { summary, generate, generating, generateError }
}

/** Owner opt-in; disabling also deletes the organization's cached summaries on the API. */
export function useInsightsOptIn() {
  const repository = useInsightsRepository()
  const auth = useAuthStore()
  const pending = ref(false)

  async function update(enabled: boolean) {
    pending.value = true

    try {
      auth.updateOrganization(await repository.updateOptIn(enabled))
      clearNuxtData(key => key.startsWith('insights:'))
    }
    finally {
      pending.value = false
    }
  }

  return { update, pending }
}
