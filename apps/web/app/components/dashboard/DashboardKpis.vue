<script setup lang="ts">
import type { DashboardKpis } from '~/types/analytics'

defineProps<{
  kpis: DashboardKpis | null | undefined
  loading: boolean
  refreshing: boolean
  error: unknown
}>()

const emit = defineEmits<{ retry: [] }>()

const { currency } = useOrganization()

const money = (value: string | null) => formatMoney(value, currency.value)
</script>

<template>
  <section aria-label="Indicadores do período">
    <UiCard
      v-if="error"
      padding="none"
    >
      <UiErrorState
        compact
        title="Não foi possível carregar os indicadores"
        :message="errorMessage(error)"
        :retrying="refreshing"
        @retry="emit('retry')"
      />
    </UiCard>
    <div
      v-else-if="loading || !kpis"
      class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4"
      aria-busy="true"
    >
      <span class="sr-only">Carregando indicadores</span>
      <UiSkeleton
        v-for="index in 4"
        :key="index"
        class="h-[116px] rounded-2xl"
      />
    </div>
    <div
      v-else
      class="grid grid-cols-2 gap-3 transition-opacity sm:gap-4 xl:grid-cols-4"
      :class="{ 'opacity-60': refreshing }"
    >
      <KpiCard
        label="Receita"
        :metric="kpis.revenue"
        :format="money"
      />
      <KpiCard
        label="Pedidos pagos"
        :metric="kpis.orders"
        :format="formatInteger"
      />
      <KpiCard
        label="Ticket médio"
        :metric="kpis.average_order_value"
        :format="money"
      />
      <KpiCard
        label="Clientes ativos"
        :metric="kpis.customers"
        :format="formatInteger"
      />
    </div>
  </section>
</template>
