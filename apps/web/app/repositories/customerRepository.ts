import type { Paginated, ResourceEnvelope } from '~/types/api'
import type { Customer, CustomerDetail, CustomerInput, CustomerListParams } from '~/types/customer'

export function useCustomerRepository() {
  const { apiFetch } = useApiClient()

  return {
    list: (params: CustomerListParams) =>
      apiFetch<Paginated<Customer>>('/customers', { query: { ...params } }),

    get: async (id: string) =>
      (await apiFetch<ResourceEnvelope<CustomerDetail>>(`/customers/${encodeURIComponent(id)}`)).data,

    create: async (input: CustomerInput) =>
      (await apiFetch<ResourceEnvelope<Customer>>('/customers', { method: 'POST', body: { ...input } })).data,

    /** PATCH semantics: only the changed fields are sent. */
    update: async (id: string, changes: Partial<CustomerInput>) =>
      (await apiFetch<ResourceEnvelope<Customer>>(`/customers/${encodeURIComponent(id)}`, { method: 'PATCH', body: { ...changes } })).data,

    /** Customers with transactions are archived (history kept); others are removed. */
    remove: (id: string) =>
      apiFetch<null>(`/customers/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  }
}
