import { beforeEach, describe, expect, it } from 'vitest'
import { useAuthStore } from '~/stores/auth'
import { enumFilter, textFilter, useListQuery } from '~/composables/useListQuery'

function setup() {
  return useListQuery<{ q: string | undefined, status: 'active' | 'inactive' | undefined }>({
    q: textFilter,
    status: enumFilter(['active', 'inactive'] as const),
  })
}

describe('useListQuery', () => {
  let router: ReturnType<typeof useRouter>

  beforeEach(async () => {
    useAuthStore().setSession({ id: 'u1', name: 'Owner', email: 'owner@example.com' }, null)
    router = useRouter()
    await router.replace({ path: '/products', query: {} })
  })

  it('reads filters, page and per_page from the URL', async () => {
    await router.replace({ path: '/products', query: { q: ' mouse ', status: 'inactive', page: '3', per_page: '25' } })
    const list = setup()

    expect(list.params.value).toEqual({ q: 'mouse', status: 'inactive', page: 3, per_page: 25 })
    expect(list.hasActiveFilters.value).toBe(true)
  })

  it('ignores invalid URL values instead of sending them to the API', async () => {
    await router.replace({ path: '/products', query: { status: 'deleted', page: '-1', per_page: '500', q: '   ' } })
    const list = setup()

    expect(list.params.value).toEqual({ page: 1, per_page: 15 })
    expect(list.hasActiveFilters.value).toBe(false)
  })

  it('resets to the first page when a filter changes', async () => {
    await router.replace({ path: '/products', query: { page: '4' } })
    const list = setup()

    await list.setFilter('q', 'cabo')

    expect(router.currentRoute.value.query).toEqual({ q: 'cabo' })
    expect(list.page.value).toBe(1)
  })

  it('keeps defaults out of the URL', async () => {
    const list = setup()

    await list.setPage(2)
    expect(router.currentRoute.value.query).toEqual({ page: '2' })

    await list.setPerPage(50)
    expect(router.currentRoute.value.query).toEqual({ per_page: '50' })

    await list.setPerPage(15)
    expect(router.currentRoute.value.query).toEqual({})
  })

  it('clears every filter but keeps the page size', async () => {
    await router.replace({ path: '/products', query: { q: 'x', status: 'active', page: '2', per_page: '25' } })
    const list = setup()

    await list.clearFilters()

    expect(router.currentRoute.value.query).toEqual({ per_page: '25' })
  })
})
