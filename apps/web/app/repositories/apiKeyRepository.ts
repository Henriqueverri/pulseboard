import type { ResourceEnvelope } from '~/types/api'
import type { ApiKey, ApiKeyInput, CreatedApiKey } from '~/types/api-key'

/** Listing is open to members; creating and revoking require the owner role (the API answers 403). */
export function useApiKeyRepository() {
  const { apiFetch } = useApiClient()

  return {
    list: async () => (await apiFetch<{ data: ApiKey[] }>('/api-keys')).data,

    /** The response is sent with `Cache-Control: no-store` and is the only one with `plain_text_key`. */
    create: async (input: ApiKeyInput) =>
      (await apiFetch<ResourceEnvelope<CreatedApiKey>>('/api-keys', { method: 'POST', body: { ...input } })).data,

    /** Idempotent: revoking an already revoked key keeps the original `revoked_at`. */
    revoke: async (id: string) => {
      await apiFetch<unknown>(`/api-keys/${encodeURIComponent(id)}`, { method: 'DELETE' })
    },
  }
}
