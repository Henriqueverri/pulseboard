<script setup lang="ts">
import { PhArrowRight } from '@phosphor-icons/vue'

useHead({ title: 'Dashboard · PulseBoard' })

const period = useReportingPeriod()
const { kpis, revenue, status, products, customers } = useDashboard(period)
const { currency } = useOrganization()
const { available: insightsAvailable } = useInsightsAvailability()

const loading = (state: { status: Ref<string>, data: Ref<unknown> }) =>
  state.status.value === 'pending' && !state.data.value
const refreshing = (state: { status: Ref<string>, data: Ref<unknown> }) =>
  state.status.value === 'pending' && Boolean(state.data.value)

/** Period actually used by the API when available, otherwise the one requested. */
const caption = computed(() => {
  const meta = kpis.data.value?.meta
  const current = meta?.period ?? period.range.value
  const previous = meta?.previous_period ?? previousRange(period.range.value)

  return `${formatCivilRange(current.from, current.to)} · comparado a ${formatCivilRange(previous.from, previous.to)}`
})

const granularity = computed({
  get: () => period.granularity.value,
  set: value => period.setGranularity(value),
})

const productItems = computed(() => productRankingItems(products.data.value?.data ?? [], currency.value))
const customerItems = computed(() => customerRankingItems(customers.data.value?.data ?? [], currency.value))
</script>

<template>
  <div>
    <PageHeader title="Dashboard">
      <template #description>
        <span class="tabular">{{ caption }}</span>
        <span class="mt-0.5 block text-xs">
          Indicadores calculados pela API a partir das transações pagas, da demo ou recebidas pela
          <NuxtLink
            to="/settings/api-keys"
            class="font-medium text-ink underline-offset-2 hover:underline"
          >integração por API</NuxtLink>.
        </span>
      </template>
      <template #actions>
        <PeriodFilter :period="period" />
      </template>
    </PageHeader>

    <div class="flex flex-col gap-6">
      <DashboardKpis
        :kpis="kpis.data.value?.data"
        :loading="loading(kpis)"
        :refreshing="refreshing(kpis)"
        :error="kpis.error.value"
        @retry="kpis.refresh()"
      />

      <InsightsCard
        v-if="insightsAvailable"
        :period="period"
      />

      <div class="grid gap-6 lg:grid-cols-3">
        <DashboardRevenueCard
          v-model:granularity="granularity"
          class="lg:col-span-2"
          :response="revenue.data.value"
          :loading="loading(revenue)"
          :refreshing="refreshing(revenue)"
          :error="revenue.error.value"
          @retry="revenue.refresh()"
        />
        <DashboardStatusCard
          :response="status.data.value"
          :loading="loading(status)"
          :refreshing="refreshing(status)"
          :error="status.error.value"
          @retry="status.refresh()"
        />
      </div>

      <div class="grid gap-6 lg:grid-cols-2">
        <DataCard
          title="Top 5 produtos"
          description="Por receita de transações pagas"
          :loading="loading(products)"
          :refreshing="refreshing(products)"
          :error="products.error.value"
          :empty="productItems.length === 0"
          empty-title="Nenhum produto vendido no período"
          @retry="products.refresh()"
        >
          <template #actions>
            <UiButton
              :to="{ path: '/analytics/products', query: period.query.value }"
              variant="ghost"
              size="sm"
            >
              Ver análise
              <template #trailing>
                <PhArrowRight :size="14" />
              </template>
            </UiButton>
          </template>
          <RankingList
            :items="productItems"
            label="Produtos com maior receita"
          />
        </DataCard>

        <DataCard
          title="Top 5 clientes"
          description="Por receita de transações pagas"
          :loading="loading(customers)"
          :refreshing="refreshing(customers)"
          :error="customers.error.value"
          :empty="customerItems.length === 0"
          empty-title="Nenhum cliente comprou no período"
          @retry="customers.refresh()"
        >
          <template #actions>
            <UiButton
              :to="{ path: '/analytics/customers', query: period.query.value }"
              variant="ghost"
              size="sm"
            >
              Ver análise
              <template #trailing>
                <PhArrowRight :size="14" />
              </template>
            </UiButton>
          </template>
          <RankingList
            :items="customerItems"
            label="Clientes com maior receita"
          />
        </DataCard>
      </div>
    </div>
  </div>
</template>
