import type { IsoDateTime } from './api'

/** Computed by the API: a revoked key stays revoked even after its expiration date. */
export type ApiKeyStatus = 'active' | 'revoked' | 'expired'

/** Expiration options accepted by `POST /api-keys`; `null` means the key does not expire. */
export const API_KEY_EXPIRATION_DAYS = [30, 90, 365] as const

export type ApiKeyExpiration = typeof API_KEY_EXPIRATION_DAYS[number] | null

/** `ApiKeyResource`: never contains the secret. */
export interface ApiKey {
  id: string
  name: string
  /** Public part of the key (`pb_<prefix>_<secret>`), safe to display. */
  prefix: string
  status: ApiKeyStatus
  /** `null` when the user who created the key no longer exists. */
  created_by: { id: string, name: string } | null
  last_used_at: IsoDateTime | null
  expires_at: IsoDateTime | null
  revoked_at: IsoDateTime | null
  created_at: IsoDateTime
}

/**
 * The single `POST /api-keys` response that carries the secret. Keep it in component state
 * only for as long as it is displayed: never in a store, cache, storage, URL or log.
 */
export interface CreatedApiKey extends ApiKey {
  plain_text_key: string
}

export interface ApiKeyInput {
  name: string
  expires_in_days: ApiKeyExpiration
}
