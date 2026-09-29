<script setup lang="ts">
import type { TransactionStatusRow } from '~/types/analytics'
import type { TransactionStatus } from '~/types/transaction'
import { TRANSACTION_STATUS_LABELS } from '~/types/transaction'
import type { Polarity } from '~/utils/comparison'

useHead({ title: 'Transações · Analytics · PulseBoard' })

const period = useReportingPeriod()
const { data, status, error, refresh } = useTransactionStatusAnalytics(period)
const { currency } = useOrganization()

const loading = computed(() => status.value === 'pending' && !data.value)
const refreshing = computed(() => status.value === 'pending' && Boolean(data.value))
const rows = computed(() => data.value?.data ?? [])
const total = computed(() => rows.value.reduce((sum, row) => sum + row.orders.value, 0))

/** More refunds or cancellations is bad news; pending is only in-flight. */
const POLARITY: Record<TransactionStatus, Polarity> = {
  paid: 'positive',
  refunded: 'negative',
  pending: 'neutral',
  canceled: 'negative',
}

const COLORS: Record<TransactionStatus, { token: string, dot: string, bar: string }> = {
  paid: { token: 'success', dot: 'bg-success', bar: 'bg-success' },
  refunded: { token: 'info', dot: 'bg-info', bar: 'bg-info' },
  pending: { token: 'warning', dot: 'bg-warning', bar: 'bg-warning' },
  canceled: { token: 'danger', dot: 'bg-danger', bar: 'bg-danger' },
}

const slices = computed(() => rows.value.map(row => ({
  label: TRANSACTION_STATUS_LABELS[row.status],
  value: row.orders.value,
  color: COLORS[row.status].token,
})))

const chartLabel = computed(() => `Distribuição de ${formatInteger(total.value)} transações por status: ${
  rows.value.map(row => `${TRANSACTION_STATUS_LABELS[row.status]} ${formatPercent(row.percentage.value)}`).join(', ')
}.`)

const columns = [
  { key: 'status', label: 'Status' },
  { key: 'orders', label: 'Transações', align: 'right' as const },
  { key: 'revenue', label: 'Valor', align: 'right' as const, hideBelow: 'sm' as const },
  { key: 'percentage', label: 'Participação', align: 'right' as const },
]
</script>

<template>
  <div class="flex flex-col gap-6">
    <UiCard
      v-if="error"
      padding="none"
    >
      <UiErrorState
        :message="errorMessage(error)"
        :retrying="refreshing"
        @retry="refresh()"
      />
    </UiCard>

    <template v-else>
      <UiAlert
        tone="info"
        title="Esta aba considera todos os status"
      >
        As demais métricas do PulseBoard contam só transações pagas. Aqui o valor de cada status é a soma das transações daquele status; só a linha "Pago" é receita.
      </UiAlert>

      <div class="grid gap-6 lg:grid-cols-3">
        <DataCard
          title="Distribuição"
          :loading="loading"
          :refreshing="refreshing"
          :empty="Boolean(data) && total === 0"
          empty-title="Nenhuma transação no período"
        >
          <template #skeleton>
            <UiSkeleton class="mx-auto size-48 rounded-full" />
          </template>
          <div class="mx-auto size-48">
            <LazyStatusDonutChart
              :slices="slices"
              :label="chartLabel"
            >
              <div class="text-center">
                <p class="text-2xl font-semibold text-ink tabular">
                  {{ formatInteger(total) }}
                </p>
                <p class="text-xs text-ink/65">
                  transações
                </p>
              </div>
            </LazyStatusDonutChart>
          </div>
        </DataCard>

        <UiCard
          title="Por status"
          description="Variação em relação ao período anterior"
          padding="none"
          class="lg:col-span-2"
        >
          <DataTable
            :columns="columns"
            :rows="rows"
            :row-key="(row: TransactionStatusRow) => row.status"
            caption="Transações, valor e participação por status"
            :loading="loading"
            :busy="refreshing"
            :skeleton-rows="4"
            class="mt-3"
          >
            <template #cell-status="{ row }">
              <span class="flex items-center gap-2 font-medium">
                <span
                  class="size-2 shrink-0 rounded-full"
                  :class="COLORS[row.status].dot"
                  aria-hidden="true"
                />
                {{ TRANSACTION_STATUS_LABELS[row.status] }}
              </span>
              <span class="text-xs text-ink/65 tabular sm:hidden">
                {{ formatMoney(row.revenue.value, currency) }}
              </span>
            </template>
            <template #cell-orders="{ row }">
              <div class="flex flex-col items-end gap-0.5">
                <span class="tabular">{{ formatInteger(row.orders.value) }}</span>
                <ChangeIndicator
                  :metric="row.orders"
                  :polarity="POLARITY[row.status]"
                  size="sm"
                />
              </div>
            </template>
            <template #cell-revenue="{ row }">
              <div class="flex flex-col items-end gap-0.5">
                <span class="tabular">{{ formatMoney(row.revenue.value, currency) }}</span>
                <ChangeIndicator
                  :metric="row.revenue"
                  :polarity="POLARITY[row.status]"
                  size="sm"
                />
              </div>
            </template>
            <template #cell-percentage="{ row }">
              <div class="flex flex-col items-end gap-1">
                <span class="flex items-center gap-1.5">
                  <ChangeIndicator
                    :metric="row.percentage"
                    :polarity="POLARITY[row.status]"
                    size="sm"
                    class="max-sm:hidden"
                  />
                  <span class="font-medium tabular">{{ formatPercent(row.percentage.value) }}</span>
                </span>
                <div
                  class="h-1.5 w-20 overflow-hidden rounded-full bg-ink/[0.05]"
                  aria-hidden="true"
                >
                  <div
                    class="h-full rounded-full"
                    :class="COLORS[row.status].bar"
                    :style="{ width: `${row.percentage.value ?? 0}%` }"
                  />
                </div>
              </div>
            </template>
          </DataTable>
          <template #footer>
            <p class="text-xs text-ink/65">
              Participação = parcela das transações do período. "—" quando o período não tem transações.
            </p>
          </template>
        </UiCard>
      </div>
    </template>
  </div>
</template>
