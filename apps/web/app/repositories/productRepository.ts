import type { Paginated, ResourceEnvelope } from '~/types/api'
import type { Product, ProductDetail, ProductInput, ProductListParams } from '~/types/product'

export function useProductRepository() {
  const { apiFetch } = useApiClient()

  return {
    list: (params: ProductListParams) =>
      apiFetch<Paginated<Product>>('/products', { query: { ...params } }),

    get: async (id: string) =>
      (await apiFetch<ResourceEnvelope<ProductDetail>>(`/products/${encodeURIComponent(id)}`)).data,

    create: async (input: ProductInput) =>
      (await apiFetch<ResourceEnvelope<Product>>('/products', { method: 'POST', body: { ...input } })).data,

    /** PATCH semantics: only the changed fields are sent. */
    update: async (id: string, changes: Partial<ProductInput>) =>
      (await apiFetch<ResourceEnvelope<Product>>(`/products/${encodeURIComponent(id)}`, { method: 'PATCH', body: { ...changes } })).data,

    /** Products with sales are archived (history kept); others are removed. */
    remove: (id: string) =>
      apiFetch<null>(`/products/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  }
}
