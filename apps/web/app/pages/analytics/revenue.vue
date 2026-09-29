<script setup lang="ts">
import type { RevenueBucket } from '~/types/analytics'
import type { Granularity } from '~/utils/period'

useHead({ title: 'Receita · Analytics · PulseBoard' })

const period = useReportingPeriod()
const { data, status, error, refresh } = useRevenueAnalytics(period)
const { currency } = useOrganization()

const metric = ref<'revenue' | 'orders'>('revenue')

const granularity = computed({
  get: () => period.granularity.value,
  set: (value: Granularity) => period.setGranularity(value),
})

const granularityOptions: { value: Granularity, label: string }[] = [
  { value: 'day', label: 'Dia' },
  { value: 'week', label: 'Semana' },
  { value: 'month', label: 'Mês' },
]

const metricOptions = [
  { value: 'revenue' as const, label: 'Receita' },
  { value: 'orders' as const, label: 'Pedidos' },
]

const loading = computed(() => status.value === 'pending' && !data.value)
const refreshing = computed(() => status.value === 'pending' && Boolean(data.value))
const empty = computed(() => data.value?.summary.orders.value === 0 && Number(data.value.summary.revenue.value) === 0)

const columns = [
  { key: 'period', label: 'Período' },
  { key: 'revenue', label: 'Receita', align: 'right' as const },
  { key: 'orders', label: 'Pedidos', align: 'right' as const },
]

const money = (value: string | null) => formatMoney(value, currency.value)
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
      <section
        aria-label="Resumo de receita"
        class="grid grid-cols-2 gap-3 sm:gap-4"
      >
        <template v-if="data">
          <KpiCard
            label="Receita"
            :metric="data.summary.revenue"
            :format="money"
          />
          <KpiCard
            label="Pedidos pagos"
            :metric="data.summary.orders"
            :format="formatInteger"
          />
        </template>
        <template v-else>
          <UiSkeleton
            v-for="index in 2"
            :key="index"
            class="h-[116px] rounded-2xl"
          />
        </template>
      </section>

      <DataCard
        title="Evolução no período"
        description="Somente transações pagas, agrupadas no fuso da organização"
        :loading="loading"
        :refreshing="refreshing"
        :empty="empty"
        empty-title="Nenhuma venda no período"
        empty-description="Escolha outro período para ver a evolução."
      >
        <template #actions>
          <UiSegmentedControl
            v-model="metric"
            :options="metricOptions"
            label="Métrica do gráfico"
          />
          <UiSegmentedControl
            v-model="granularity"
            :options="granularityOptions"
            label="Agrupar por"
          />
        </template>
        <template #skeleton>
          <UiSkeleton class="h-64 w-full rounded-lg sm:h-72" />
        </template>
        <div
          v-if="data"
          class="min-h-64 sm:min-h-72"
        >
          <LazyRevenueChart
            :buckets="data.data"
            :granularity="data.meta.granularity"
            :currency="currency"
            :metric="metric"
            :sr-table="false"
          />
        </div>
      </DataCard>

      <UiCard
        title="Valores por período"
        padding="none"
      >
        <DataTable
          :columns="columns"
          :rows="data?.data ?? []"
          :row-key="(row: RevenueBucket) => row.bucket"
          caption="Receita e pedidos pagos por período"
          :loading="loading"
          :busy="refreshing"
          :skeleton-rows="6"
          class="mt-3"
        >
          <template #cell-period="{ row }">
            <span class="tabular">{{ bucketRangeLabel(row, data!.meta.granularity) }}</span>
          </template>
          <template #cell-revenue="{ row }">
            <span class="tabular">{{ money(row.revenue) }}</span>
          </template>
          <template #cell-orders="{ row }">
            <span class="tabular">{{ formatInteger(row.orders) }}</span>
          </template>
        </DataTable>
      </UiCard>
    </template>
  </div>
</template>
