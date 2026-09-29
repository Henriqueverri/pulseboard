import type { LocationQuery, LocationQueryRaw } from 'vue-router'
import type { CivilDate } from '~/types/api'
import type { CivilRange, Granularity, PeriodPreset } from '~/utils/period'

function first(value: LocationQuery[string] | undefined): string | undefined {
  const candidate = Array.isArray(value) ? value[0] : value

  return typeof candidate === 'string' ? candidate : undefined
}

/** Previous period of the same length, right before `from` (mirrors `ReportingPeriod::previous()`). */
export function previousRange(range: CivilRange): CivilRange {
  const days = daysBetween(range.from, range.to)

  return { from: addDays(range.from, -days), to: addDays(range.from, -1) }
}

/**
 * Reporting period shared by the Dashboard and Analytics, kept in the URL:
 * `?period=7d` for presets (the 30-day default is omitted) or `?from=&to=` for a custom range.
 * Presets resolve against today in the organization timezone, and both dates are always sent,
 * so the API never falls back to its own default. Invalid URL values fall back to the default.
 */
export function useReportingPeriod() {
  const route = useRoute()
  const router = useRouter()
  const { timezone } = useOrganization()

  const today = computed<CivilDate>(() => todayIn(timezone.value))

  const customRange = computed<CivilRange | null>(() => {
    const from = first(route.query.from)
    const to = first(route.query.to)

    return from && to && validateRange(from, to) === null ? { from, to } : null
  })

  const preset = computed<PeriodPreset | 'custom'>(() => {
    if (customRange.value) {
      return 'custom'
    }

    const value = first(route.query.period)

    return isPeriodPreset(value) ? value : DEFAULT_PRESET
  })

  const range = computed<CivilRange>(() =>
    customRange.value ?? resolvePreset(preset.value as PeriodPreset, today.value),
  )

  const days = computed(() => daysBetween(range.value.from, range.value.to))
  const suggestedGranularity = computed(() => suggestGranularity(days.value))

  const granularity = computed<Granularity>(() => {
    const value = first(route.query.granularity)

    return isGranularity(value) ? value : suggestedGranularity.value
  })

  const params = computed(() => ({ from: range.value.from, to: range.value.to }))

  /** Period keys of the current URL, to carry the same period to another page. */
  const query = computed<LocationQueryRaw>(() => Object.fromEntries(
    ['period', 'from', 'to'].flatMap((key) => {
      const value = first(route.query[key])

      return value ? [[key, value]] : []
    }),
  ))

  function replace(patch: Record<string, string | undefined>) {
    const next: LocationQueryRaw = { ...route.query }

    for (const [key, value] of Object.entries(patch)) {
      next[key] = value
    }

    return router.replace({
      query: Object.fromEntries(Object.entries(next).filter(([, value]) => value !== undefined && value !== null)),
    })
  }

  return {
    today,
    preset,
    range,
    days,
    params,
    query,
    granularity,
    suggestedGranularity,
    /** A new period drops a manually chosen granularity, so the suggestion fits the new length. */
    setPreset: (value: PeriodPreset) => replace({
      period: value === DEFAULT_PRESET ? undefined : value,
      from: undefined,
      to: undefined,
      granularity: undefined,
    }),
    /** Returns the validation message instead of navigating when the range is invalid. */
    setCustomRange: async (from: string, to: string): Promise<string | null> => {
      const invalid = validateRange(from, to)

      if (invalid) {
        return invalid
      }

      await replace({ period: undefined, from, to, granularity: undefined })

      return null
    },
    setGranularity: (value: Granularity) => replace({
      granularity: value === suggestedGranularity.value ? undefined : value,
    }),
  }
}

export type ReportingPeriod = ReturnType<typeof useReportingPeriod>
