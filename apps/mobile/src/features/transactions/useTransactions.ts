import { keepPreviousData, useInfiniteQuery, useQuery } from '@tanstack/react-query';

import { getTransaction, listTransactions, type TransactionFilters } from '@/api/transactions';

export function useTransactions(organizationId: string, filters: TransactionFilters) {
  const { status, range, search } = filters;

  return useInfiniteQuery({
    queryKey: ['org', organizationId, 'transactions', status, range.from, range.to, search],
    queryFn: ({ pageParam, signal }) => listTransactions(filters, pageParam, signal),
    initialPageParam: 1,
    getNextPageParam: ({ meta }) => (meta.current_page < meta.last_page ? meta.current_page + 1 : undefined),
    placeholderData: keepPreviousData,
  });
}

export function useTransaction(organizationId: string, id: string) {
  return useQuery({
    queryKey: ['org', organizationId, 'transaction', id],
    queryFn: ({ signal }) => getTransaction(id, signal),
  });
}
