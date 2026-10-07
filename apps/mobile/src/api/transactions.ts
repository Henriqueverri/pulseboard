import type { CivilRange } from '@/lib/period';

import { apiRequest } from './client';
import type { Paginated, Transaction, TransactionDetail, TransactionStatus } from './types';

export const TRANSACTIONS_PER_PAGE = 20;

export interface TransactionFilters {
  status: TransactionStatus | null;
  range: CivilRange;
  /** Customer name/e-mail (partial), external id or transaction id (exact), as the API searches. */
  search: string;
}

export function listTransactions(
  { status, range, search }: TransactionFilters,
  page: number,
  signal?: AbortSignal,
): Promise<Paginated<Transaction>> {
  return apiRequest<Paginated<Transaction>>('/transactions', {
    query: { status, q: search, from: range.from, to: range.to, page, per_page: TRANSACTIONS_PER_PAGE },
    signal,
  });
}

export function getTransaction(id: string, signal?: AbortSignal): Promise<TransactionDetail> {
  return apiRequest<{ data: TransactionDetail }>(`/transactions/${encodeURIComponent(id)}`, { signal }).then(
    (response) => response.data,
  );
}
