import type { IsoDateTime, Money } from './api'

export type ProductStatus = 'active' | 'inactive'

export const PRODUCT_STATUSES: readonly ProductStatus[] = ['active', 'inactive']

/** `ProductResource`. */
export interface Product {
  id: string
  name: string
  sku: string | null
  price: Money
  status: ProductStatus
  created_at: IsoDateTime
  updated_at: IsoDateTime
}

/** `GET /products/{id}`: totals over paid transactions only. */
export interface ProductDetail extends Product {
  units_sold: number
  revenue: Money
}

export interface ProductInput {
  name: string
  sku: string | null
  price: Money
  status: ProductStatus
}

export interface ProductListParams {
  q?: string
  status?: ProductStatus
  page?: number
  per_page?: number
}
