import { useTransactionRepository } from '~/repositories/transactionRepository'
import { TRANSACTION_STATUSES } from '~/types/transaction'
import type { TransactionStatus } from '~/types/transaction'

export interface TransactionFilters {
  [key: string]: string | undefined
  q: string | undefined
  status: TransactionStatus | undefined
  customer_id: string | undefined
  from: string | undefined
  to: string | undefined
}

export function useTransactions() {
  const repository = useTransactionRepository()
  const list = useListQuery<TransactionFilters>({
    q: textFilter,
    status: enumFilter(TRANSACTION_STATUSES),
    customer_id: uuidFilter,
    from: civilDateFilter,
    to: civilDateFilter,
  })

  return {
    list,
    ...usePaginatedList('transactions', list, params => repository.list(params)),
  }
}

export function useTransaction(id: MaybeRefOrGetter<string>) {
  const repository = useTransactionRepository()
  const auth = useAuthStore()

  return useAsyncData(
    () => `transactions:detail:${auth.organization?.id ?? 'none'}:${toValue(id)}`,
    () => repository.get(toValue(id)),
  )
}
