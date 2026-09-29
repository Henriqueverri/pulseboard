import type { Paginated } from '~/types/api'
import type { ListQuery } from './useListQuery'

/**
 * Fetches one page of a tenant resource. The cache key carries the organization, and the
 * previous page stays on screen while the next one loads.
 */
export function usePaginatedList<T, F extends Record<string, string | undefined>>(
  resource: string,
  list: ListQuery<F>,
  fetcher: (params: ListQuery<F>['params']['value']) => Promise<Paginated<T>>,
) {
  const auth = useAuthStore()

  const { data, status, error, refresh } = useAsyncData(
    () => `${resource}:list:${auth.organization?.id ?? 'none'}`,
    () => fetcher(list.params.value),
    { watch: [list.params] },
  )

  // A page past the end (e.g. after deleting the last row of the last page): go to the new last page.
  watch(data, (value) => {
    if (value && value.data.length === 0 && value.meta.current_page > 1 && value.meta.last_page < value.meta.current_page) {
      list.setPage(Math.max(1, value.meta.last_page))
    }
  })

  return {
    rows: computed(() => data.value?.data ?? []),
    meta: computed(() => data.value?.meta ?? null),
    /** No data yet: show skeletons. */
    initialLoading: computed(() => status.value === 'pending' && !data.value),
    /** Refetching with data on screen: dim it. */
    refreshing: computed(() => status.value === 'pending' && Boolean(data.value)),
    error,
    refresh,
  }
}
