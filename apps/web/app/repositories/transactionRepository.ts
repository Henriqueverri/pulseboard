import type { Paginated, ResourceEnvelope } from '~/types/api'
import type { Transaction, TransactionDetail, TransactionListParams } from '~/types/transaction'

/** Read-only: the API exposes no transaction writes. */
export function useTransactionRepository() {
  const { apiFetch } = useApiClient()

  return {
    /** `from`/`to` are civil dates in the organization timezone; either may be sent alone. */
    list: (params: TransactionListParams) =>
      apiFetch<Paginated<Transaction>>('/transactions', { query: { ...params } }),

    get: async (id: string) =>
      (await apiFetch<ResourceEnvelope<TransactionDetail>>(`/transactions/${encodeURIComponent(id)}`)).data,
  }
}
