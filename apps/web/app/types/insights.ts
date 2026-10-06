import type { AnalyticsMeta, IsoDateTime, Money } from './api'

/** `PeriodSummarySchema::KINDS`: drives the finding's icon and tone, never its text. */
export const INSIGHT_KINDS = ['positive', 'negative', 'neutral', 'attention'] as const
export type InsightKind = typeof INSIGHT_KINDS[number]

/** `PeriodSummarySchema::DESTINATIONS`: screens the front links to with the same period, never URLs. */
export const INSIGHT_DESTINATIONS = [
  'dashboard',
  'analytics.revenue',
  'analytics.products',
  'analytics.customers',
  'analytics.transactions',
  'transactions.refunded',
  'transactions.canceled',
  'transactions.pending',
] as const
export type InsightDestination = typeof INSIGHT_DESTINATIONS[number]

/** `PeriodSummaryCaveats`: limitations of the period's numbers, decided by the server. */
export const INSIGHT_CAVEATS = ['partial_period', 'no_sales', 'no_previous_data', 'low_volume', 'status_is_current'] as const
export type InsightCaveat = typeof INSIGHT_CAVEATS[number]

/**
 * One cited metric (`App\Data\Ai\Evidence`): the model only names the `ref`; label, value
 * and comparison come from the analytics services. Money values are decimal strings.
 */
export interface InsightEvidence {
  ref: string
  label: string
  format: 'money' | 'count'
  polarity: 'positive' | 'negative' | 'neutral'
  destination: InsightDestination
  value: Money | number | null
  previous: Money | number | null
  change: number | null
}

export interface InsightFinding {
  kind: InsightKind
  title: string
  explanation: string
  destination: InsightDestination
  evidence: InsightEvidence[]
}

export interface InsightAttentionPoint {
  text: string
  evidence: InsightEvidence[]
}

export interface PeriodSummary {
  headline: string
  overview: string
  findings: InsightFinding[]
  attention_points: InsightAttentionPoint[]
  caveats: InsightCaveat[]
}

export interface InsightMeta {
  generated_at: IsoDateTime
  model: string
  prompt_version: string
  /** `true` when served from the cache instead of a new generation. */
  cached: boolean
}

/** `GET`/`POST /insights/period-summary`; `data` is null when nothing was generated for this data yet. */
export type PeriodSummaryResponse
  = | { data: PeriodSummary, meta: AnalyticsMeta & { insight: InsightMeta } }
    | { data: null }

/** Stable `code` of the insights errors (`App\Exceptions\AiException`). */
export const AI_ERROR_CODES = {
  disabled: 'ai_disabled',
  notEnabled: 'ai_not_enabled',
  quotaExceeded: 'ai_quota_exceeded',
  providerUnavailable: 'ai_provider_unavailable',
  timeout: 'ai_timeout',
  invalidOutput: 'ai_invalid_output',
} as const
