<script setup lang="ts">
import type { TransactionStatusResponse } from '~/types/analytics'
import type { TransactionStatus } from '~/types/transaction'
import { TRANSACTION_STATUS_LABELS } from '~/types/transaction'

const props = defineProps<{
  response: TransactionStatusResponse | null | undefined
  loading: boolean
  refreshing: boolean
  error: unknown
}>()

const emit = defineEmits<{ retry: [] }>()

const COLORS: Record<TransactionStatus, { token: string, dot: string }> = {
  paid: { token: 'success', dot: 'bg-success' },
  refunded: { token: 'info', dot: 'bg-info' },
  pending: { token: 'warning', dot: 'bg-warning' },
  canceled: { token: 'danger', dot: 'bg-danger' },
}

const rows = computed(() => props.response?.data ?? [])
const total = computed(() => rows.value.reduce((sum, row) => sum + row.orders.value, 0))

const slices = computed(() => rows.value.map(row => ({
  label: TRANSACTION_STATUS_LABELS[row.status],
  value: row.orders.value,
  color: COLORS[row.status].token,
})))

const chartLabel = computed(() => `Distribuição de ${formatInteger(total.value)} transações por status: ${
  rows.value.map(row => `${TRANSACTION_STATUS_LABELS[row.status]} ${formatPercent(row.percentage.value)}`).join(', ')
}.`)
</script>

<template>
  <DataCard
    title="Transações por status"
    description="Todos os status, não só as pagas"
    :loading="loading || (!response && !error)"
    :refreshing="refreshing"
    :error="error"
    :empty="Boolean(response) && total === 0"
    empty-title="Nenhuma transação no período"
    @retry="emit('retry')"
  >
    <template #skeleton>
      <div class="flex flex-col items-center gap-5">
        <UiSkeleton class="size-40 rounded-full" />
        <UiSkeleton class="h-24 w-full rounded-lg" />
      </div>
    </template>

    <div class="flex flex-col items-center gap-5">
      <div class="size-40">
        <LazyStatusDonutChart
          :slices="slices"
          :label="chartLabel"
        >
          <div class="text-center">
            <p class="text-xl font-semibold text-ink tabular">
              {{ formatInteger(total) }}
            </p>
            <p class="text-[11px] text-ink/65">
              transações
            </p>
          </div>
        </LazyStatusDonutChart>
      </div>
      <ul class="w-full divide-y divide-ink/[0.06] text-sm">
        <li
          v-for="row in rows"
          :key="row.status"
          class="flex items-center justify-between gap-3 py-2"
        >
          <span class="flex items-center gap-2 text-ink/70">
            <span
              class="size-2 rounded-full"
              :class="COLORS[row.status].dot"
              aria-hidden="true"
            />
            {{ TRANSACTION_STATUS_LABELS[row.status] }}
          </span>
          <span class="flex items-baseline gap-3 tabular">
            <span class="font-medium text-ink">{{ formatInteger(row.orders.value) }}</span>
            <span class="w-12 text-right text-xs text-ink/65">{{ formatPercent(row.percentage.value) }}</span>
          </span>
        </li>
      </ul>
    </div>
  </DataCard>
</template>
