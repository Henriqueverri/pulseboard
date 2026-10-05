import { useApiKeyRepository } from '~/repositories/apiKeyRepository'
import type { ApiKey, ApiKeyExpiration } from '~/types/api-key'

export function useApiKeys() {
  const repository = useApiKeyRepository()
  const auth = useAuthStore()

  return useAsyncData(
    () => `api-keys:${auth.organization?.id ?? 'none'}`,
    () => repository.list(),
  )
}

export function useApiKeyMutations() {
  const repository = useApiKeyRepository()

  return {
    create: repository.create,
    revoke: repository.revoke,
  }
}

const ONE_HOUR_MS = 3_600_000

/**
 * Whether the API issued the key with an earlier expiration than requested
 * (the public demo organization caps every key at 24 hours).
 */
export function expirationWasLimited(apiKey: Pick<ApiKey, 'created_at' | 'expires_at'>, requested: ApiKeyExpiration): boolean {
  if (apiKey.expires_at === null) {
    return false
  }
  if (requested === null) {
    return true
  }

  const expected = Date.parse(apiKey.created_at) + requested * 24 * ONE_HOUR_MS

  return Date.parse(apiKey.expires_at) < expected - ONE_HOUR_MS
}

/** `pb_<prefix>_…`: what an operator recognizes in configs and logs, without the secret. */
export function maskedApiKey(prefix: string): string {
  return `pb_${prefix}_…`
}
