<script setup lang="ts">
import type { CustomerRankingRow, CustomerSort } from '~/types/analytics'

useHead({ title: 'Clientes · Analytics · PulseBoard' })

const period = useReportingPeriod()
const { ranking, data, status, error, refresh } = useCustomerAnalytics(period)
const { currency } = useOrganization()

const loading = computed(() => status.value === 'pending' && !data.value)
const refreshing = computed(() => status.value === 'pending' && Boolean(data.value))
const rows = computed(() => data.value?.data ?? [])

const sortOptions: { value: CustomerSort, label: string }[] = [
  { value: 'revenue', label: 'Receita' },
  { value: 'orders', label: 'Pedidos' },
]

/** New + returning = active (API invariant); the bar splits the active customers. */
const mix = computed(() => {
  const summary = data.value?.summary

  if (!summary || summary.active_customers.value === 0) {
    return null
  }

  const active = summary.active_customers.value

  return {
    newCount: summary.new_customers.value,
    returningCount: summary.returning_customers.value,
    newShare: summary.new_customers.value / active,
    returningShare: summary.returning_customers.value / active,
  }
})

const columns = [
  { key: 'rank', label: 'Posição', srOnlyLabel: true, class: 'w-10' },
  { key: 'customer', label: 'Cliente' },
  { key: 'revenue', label: 'Receita', align: 'right' as const },
  { key: 'orders', label: 'Pedidos pagos', align: 'right' as const, hideBelow: 'sm' as const },
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
        aria-label="Resumo de clientes"
        class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4"
      >
        <template v-if="data">
          <KpiCard
            label="Base de clientes"
            :metric="data.summary.total_customers"
            :format="formatInteger"
          />
          <KpiCard
            label="Clientes ativos"
            :metric="data.summary.active_customers"
            :format="formatInteger"
          />
          <KpiCard
            label="Novos"
            :metric="data.summary.new_customers"
            :format="formatInteger"
          />
          <KpiCard
            label="Recorrentes"
            :metric="data.summary.returning_customers"
            :format="formatInteger"
          />
        </template>
        <template v-else>
          <UiSkeleton
            v-for="index in 4"
            :key="index"
            class="h-[116px] rounded-2xl"
          />
        </template>
      </section>

      <UiCard
        v-if="mix"
        title="Novos e recorrentes"
        description="Clientes ativos: novos fizeram a primeira compra paga no período; recorrentes já tinham comprado antes."
      >
        <div
          class="flex h-3 overflow-hidden rounded-full bg-ink/[0.05]"
          role="img"
          :aria-label="`${formatInteger(mix.newCount)} novos (${formatPercent(mix.newShare * 100)}) e ${formatInteger(mix.returningCount)} recorrentes (${formatPercent(mix.returningShare * 100)})`"
        >
          <div
            class="h-full bg-brand-500"
            :style="{ width: `${mix.newShare * 100}%` }"
          />
          <div
            class="h-full bg-purple"
            :style="{ width: `${mix.returningShare * 100}%` }"
          />
        </div>
        <ul
          class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm"
          aria-hidden="true"
        >
          <li class="flex items-center gap-2">
            <span class="size-2 rounded-full bg-brand-500" />
            <span class="text-ink/70">Novos</span>
            <span class="font-medium tabular">{{ formatInteger(mix.newCount) }}</span>
            <span class="text-xs text-ink/50 tabular">{{ formatPercent(mix.newShare * 100) }}</span>
          </li>
          <li class="flex items-center gap-2">
            <span class="size-2 rounded-full bg-purple" />
            <span class="text-ink/70">Recorrentes</span>
            <span class="font-medium tabular">{{ formatInteger(mix.returningCount) }}</span>
            <span class="text-xs text-ink/50 tabular">{{ formatPercent(mix.returningShare * 100) }}</span>
          </li>
        </ul>
      </UiCard>

      <UiCard
        title="Ranking de clientes"
        description="Somente transações pagas"
        padding="none"
      >
        <template #actions>
          <RankingControls
            :sort-options="sortOptions"
            :sort="ranking.sort.value"
            :limit="ranking.limit.value"
            @update:sort="ranking.setSort"
            @update:limit="ranking.setLimit"
          />
        </template>
        <DataTable
          :columns="columns"
          :rows="rows"
          :row-key="(row: CustomerRankingRow) => row.customer.id"
          caption="Clientes que mais compraram no período"
          :loading="loading"
          :busy="refreshing"
          :skeleton-rows="ranking.limit.value > 10 ? 10 : ranking.limit.value"
          class="mt-3"
        >
          <template #cell-rank="{ row }">
            <span class="text-xs font-semibold text-ink/50 tabular">{{ row.rank }}º</span>
          </template>
          <template #cell-customer="{ row }">
            <div class="flex min-w-0 items-center gap-3">
              <UiAvatar
                :name="row.customer.name"
                size="sm"
                class="max-sm:hidden"
              />
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                  <NuxtLink
                    v-if="!row.customer.is_deleted"
                    :to="`/customers/${row.customer.id}`"
                    class="font-medium text-ink hover:text-brand-700 hover:underline"
                  >
                    {{ row.customer.name }}
                  </NuxtLink>
                  <span
                    v-else
                    class="font-medium text-ink/70"
                  >{{ row.customer.name }}</span>
                  <UiTag
                    v-if="row.customer.is_deleted"
                    size="sm"
                  >
                    Removido
                  </UiTag>
                </div>
                <p class="truncate text-xs text-ink/50">
                  {{ row.customer.email }}
                </p>
                <p class="text-xs text-ink/50 sm:hidden">
                  {{ formatInteger(row.orders.value) }} pedidos
                </p>
              </div>
            </div>
          </template>
          <template #cell-revenue="{ row }">
            <div class="flex flex-col items-end gap-0.5">
              <span class="font-medium tabular">{{ money(row.revenue.value) }}</span>
              <ChangeIndicator
                :metric="row.revenue"
                size="sm"
              />
            </div>
          </template>
          <template #cell-orders="{ row }">
            <div class="flex flex-col items-end gap-0.5">
              <span class="tabular">{{ formatInteger(row.orders.value) }}</span>
              <ChangeIndicator
                :metric="row.orders"
                size="sm"
              />
            </div>
          </template>
          <template #empty>
            <UiEmptyState
              compact
              title="Nenhum cliente comprou no período"
              description="Escolha outro período para ver o ranking."
            />
          </template>
        </DataTable>
      </UiCard>
    </template>
  </div>
</template>
