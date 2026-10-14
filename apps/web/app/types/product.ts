import type { IsoDateTime, Money } from './api'
import type { StockStatus } from './analytics'

export type ProductStatus = 'active' | 'inactive'

/** Reported by the integrating system (the POS); read-only in PulseBoard. */
export interface ProductStock {
  quantity: number
  min_quantity: number
  status: StockStatus
  updated_at: IsoDateTime | null
}

export const PRODUCT_STATUSES: readonly ProductStatus[] = ['active', 'inactive']

/** `ProductResource`. */
export interface Product {
  id: string
  name: string
  sku: string | null
  price: Money
  status: ProductStatus
  /** The product's id in an integrating system, unique per organization. */
  external_id: string | null
  /** `null` when the product has no stock control. */
  stock: ProductStock | null
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
  external_id: string | null
}

export interface ProductListParams {
  q?: string
  status?: ProductStatus
  page?: number
  per_page?: number
}
