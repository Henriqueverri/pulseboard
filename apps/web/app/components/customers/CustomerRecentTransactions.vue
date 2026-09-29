<script setup lang="ts">
import { PhCaretRight, PhReceipt } from '@phosphor-icons/vue'
import type { TransactionSummary } from '~/types/transaction'

defineProps<{
  customerId: string
  transactions: TransactionSummary[]
}>()

const { currency, timezone } = useOrganization()
</script>

<template>
  <UiCard
    title="Transações recentes"
    description="As 5 mais recentes, de qualquer status."
    padding="none"
  >
    <template #actions>
      <UiButton
        v-if="transactions.length"
        :to="{ path: '/transactions', query: { customer_id: customerId } }"
        variant="ghost"
        size="sm"
      >
        Ver todas
      </UiButton>
    </template>

    <UiEmptyState
      v-if="!transactions.length"
      :icon="PhReceipt"
      title="Nenhuma transação"
      description="Este cliente ainda não tem pedidos registrados."
      compact
    />
    <ul
      v-else
      class="divide-y divide-ink/[0.05]"
    >
      <li
        v-for="transaction in transactions"
        :key="transaction.id"
      >
        <NuxtLink
          :to="`/transactions/${transaction.id}`"
          class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-ink/[0.02]"
        >
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-ink tabular">
              {{ formatMoney(transaction.total_amount, currency) }}
            </p>
            <p class="text-xs text-ink/65 tabular">
              {{ formatDateTime(transaction.occurred_at, timezone, { time: true }) }}
            </p>
          </div>
          <StatusBadge
            :status="transaction.status"
            size="sm"
          />
          <PhCaretRight
            :size="14"
            class="shrink-0 text-ink/30"
            aria-hidden="true"
          />
          <span class="sr-only">Ver transação</span>
        </NuxtLink>
      </li>
    </ul>
  </UiCard>
</template>
