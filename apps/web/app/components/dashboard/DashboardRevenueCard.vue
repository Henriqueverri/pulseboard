<script setup lang="ts">
import type { RevenueResponse } from '~/types/analytics'
import type { Granularity } from '~/utils/period'

const props = defineProps<{
  response: RevenueResponse | null | undefined
  loading: boolean
  refreshing: boolean
  error: unknown
}>()

const emit = defineEmits<{ retry: [] }>()
const granularity = defineModel<Granularity>('granularity', { required: true })

const { currency } = useOrganization()

const granularityOptions: { value: Granularity, label: string }[] = [
  { value: 'day', label: 'Dia' },
  { value: 'week', label: 'Semana' },
  { value: 'month', label: 'Mês' },
]

const empty = computed(() => {
  const summary = props.response?.summary

  return Boolean(summary) && summary!.orders.value === 0 && Number(summary!.revenue.value) === 0
})
</script>

<template>
  <DataCard
    title="Receita ao longo do tempo"
    description="Transações pagas no período"
    :loading="loading || (!response && !error)"
    :refreshing="refreshing"
    :error="error"
    :empty="empty"
    empty-title="Nenhuma venda no período"
    empty-description="Escolha outro período para ver a evolução da receita."
    @retry="emit('retry')"
  >
    <template #actions>
      <UiSegmentedControl
        v-model="granularity"
        :options="granularityOptions"
        label="Agrupar receita por"
      />
    </template>
    <template #skeleton>
      <UiSkeleton class="h-64 w-full rounded-lg sm:h-72" />
    </template>

    <template v-if="response">
      <p class="mb-4 flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <span class="text-2xl font-semibold tracking-tight text-ink tabular">
          {{ formatMoney(response.summary.revenue.value, currency) }}
        </span>
        <ChangeIndicator :metric="response.summary.revenue" />
        <span class="text-xs text-ink/65 tabular">
          {{ formatInteger(response.summary.orders.value) }} pedidos
        </span>
      </p>
      <div class="min-h-64 sm:min-h-72">
        <LazyRevenueChart
          :buckets="response.data"
          :granularity="response.meta.granularity"
          :currency="currency"
        />
      </div>
    </template>
  </DataCard>
</template>
