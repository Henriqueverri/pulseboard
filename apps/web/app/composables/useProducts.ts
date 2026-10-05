import { useProductRepository } from '~/repositories/productRepository'
import { PRODUCT_STATUSES } from '~/types/product'
import type { Product, ProductInput, ProductStatus } from '~/types/product'

export function useProducts() {
  const repository = useProductRepository()
  const list = useListQuery<{ q: string | undefined, status: ProductStatus | undefined }>({
    q: textFilter,
    status: enumFilter(PRODUCT_STATUSES),
  })

  return {
    list,
    ...usePaginatedList('products', list, params => repository.list(params)),
  }
}

export function useProduct(id: MaybeRefOrGetter<string>) {
  const repository = useProductRepository()
  const auth = useAuthStore()

  return useAsyncData(
    () => `products:detail:${auth.organization?.id ?? 'none'}:${toValue(id)}`,
    () => repository.get(toValue(id)),
  )
}

export function useProductMutations() {
  const repository = useProductRepository()

  return {
    create: (input: ProductInput) => repository.create(input),
    update: (product: Product, input: ProductInput) => repository.update(product.id, changedFields(product, input)),
    remove: (product: Pick<Product, 'id'>) => repository.remove(product.id),
  }
}

/** Fields that differ from the stored product (prices compared as amounts: "10" equals "10.00"). */
export function changedFields(product: Product, input: ProductInput): Partial<ProductInput> {
  const changes: Partial<ProductInput> = {}

  if (input.name !== product.name) {
    changes.name = input.name
  }
  if (input.sku !== product.sku) {
    changes.sku = input.sku
  }
  if (Number(input.price) !== Number(product.price)) {
    changes.price = input.price
  }
  if (input.status !== product.status) {
    changes.status = input.status
  }
  if (input.external_id !== product.external_id) {
    changes.external_id = input.external_id
  }

  return changes
}
