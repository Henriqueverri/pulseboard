import type { Component } from 'vue'
import { PhChartLine, PhKey, PhPackage, PhReceipt, PhSquaresFour, PhUsers } from '@phosphor-icons/vue'

export interface NavItem {
  label: string
  to: string
  icon: Component
  /** Path prefix that keeps the item active on nested pages. */
  match: string
}

export interface NavGroup {
  label: string
  items: NavItem[]
}

export const NAVIGATION: NavGroup[] = [
  {
    label: 'Visão geral',
    items: [
      { label: 'Dashboard', to: '/dashboard', icon: PhSquaresFour, match: '/dashboard' },
      { label: 'Analytics', to: '/analytics/revenue', icon: PhChartLine, match: '/analytics' },
    ],
  },
  {
    label: 'Gestão',
    items: [
      { label: 'Produtos', to: '/products', icon: PhPackage, match: '/products' },
      { label: 'Clientes', to: '/customers', icon: PhUsers, match: '/customers' },
      { label: 'Transações', to: '/transactions', icon: PhReceipt, match: '/transactions' },
    ],
  },
  {
    label: 'Integração',
    items: [
      { label: 'API Keys', to: '/settings/api-keys', icon: PhKey, match: '/settings/api-keys' },
    ],
  },
]

export function isNavItemActive(item: Pick<NavItem, 'match'>, path: string): boolean {
  return path === item.match || path.startsWith(`${item.match}/`)
}

/** Labels of static path segments; dynamic segments (ids) get the loaded record name. */
export const SEGMENT_LABELS: Record<string, string> = {
  'dashboard': 'Dashboard',
  'analytics': 'Analytics',
  'revenue': 'Receita',
  'products': 'Produtos',
  'customers': 'Clientes',
  'transactions': 'Transações',
  'settings': 'Configurações',
  'api-keys': 'API Keys',
}

export interface Breadcrumb {
  label: string
  to?: string
}

/**
 * `/products/123` → Produtos › {detailLabel}. Unknown segments use `detailLabel`
 * (or a neutral placeholder while the record loads).
 */
export function buildBreadcrumbs(path: string, detailLabel: string | null): Breadcrumb[] {
  const segments = path.split('/').filter(Boolean)
  const crumbs: Breadcrumb[] = []

  segments.forEach((segment, index) => {
    const to = `/${segments.slice(0, index + 1).join('/')}`
    const label = SEGMENT_LABELS[segment] ?? detailLabel ?? 'Detalhe'
    crumbs.push({ label, to })
  })

  // Analytics and settings have no index page: their crumb points at the first subpage.
  if (crumbs[0]?.to === '/analytics') {
    crumbs[0].to = '/analytics/revenue'
  }
  if (crumbs[0]?.to === '/settings') {
    crumbs[0].to = '/settings/api-keys'
  }

  const last = crumbs.at(-1)
  if (last) {
    delete last.to
  }

  return crumbs
}
