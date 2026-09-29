import { useCustomerRepository } from '~/repositories/customerRepository'
import type { Customer, CustomerInput } from '~/types/customer'

export function useCustomers() {
  const repository = useCustomerRepository()
  const list = useListQuery<{ q: string | undefined }>({ q: textFilter })

  return {
    list,
    ...usePaginatedList('customers', list, params => repository.list(params)),
  }
}

export function useCustomer(id: MaybeRefOrGetter<string>) {
  const repository = useCustomerRepository()
  const auth = useAuthStore()

  return useAsyncData(
    () => `customers:detail:${auth.organization?.id ?? 'none'}:${toValue(id)}`,
    () => repository.get(toValue(id)),
  )
}

export function useCustomerMutations() {
  const repository = useCustomerRepository()

  return {
    create: (input: CustomerInput) => repository.create(input),
    update: (customer: Customer, input: CustomerInput) => repository.update(customer.id, changedCustomerFields(customer, input)),
    remove: (customer: Pick<Customer, 'id'>) => repository.remove(customer.id),
  }
}

/** The API stores emails in lower case, so a case-only change is not a change. */
export function changedCustomerFields(customer: Customer, input: CustomerInput): Partial<CustomerInput> {
  const changes: Partial<CustomerInput> = {}

  if (input.name !== customer.name) {
    changes.name = input.name
  }
  if (input.email.toLowerCase() !== customer.email.toLowerCase()) {
    changes.email = input.email
  }

  return changes
}
