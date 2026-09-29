<script setup lang="ts">
import type { ProductRankingRow, ProductSort } from '~/types/analytics'

useHead({ title: 'Produtos · Analytics · PulseBoard' })

const period = useReportingPeriod()
const { ranking, data, status, error, refresh } = useProductAnalytics(period)
const { currency } = useOrganization()

const loading = computed(() => status.value === 'pending' && !data.value)
const refreshing = computed(() => status.value === 'pending' && Boolean(data.value))
const rows = computed(() => data.value?.data ?? [])

const sortOptions: { value: ProductSort, label: string }[] = [
  { value: 'revenue', label: 'Receita' },
  { value: 'units_sold', label: 'Unidades' },
]

const shares = computed(() => new Map(
  productRankingItems(rows.value, currency.value, ranking.sort.value).map(item => [item.key, item.share]),
))

const columns = [
  { key: 'rank', label: 'Posição', srOnlyLabel: true, class: 'w-10' },
  { key: 'product', label: 'Produto' },
  { key: 'revenue', label: 'Receita', align: 'right' as const },
  { key: 'units_sold', label: 'Unidades', align: 'right' as const, hideBelow: 'sm' as const },
  { key: 'share', label: 'Participação no ranking', srOnlyLabel: true, hideBelow: 'lg' as const, class: 'w-40' },
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
        aria-label="Resumo de produtos"
        class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3"
      >
        <template v-if="data">
          <KpiCard
            label="Receita"
            :metric="data.summary.revenue"
            :format="money"
          />
          <KpiCard
            label="Unidades vendidas"
            :metric="data.summary.units_sold"
            :format="formatInteger"
          />
          <KpiCard
            label="Produtos vendidos"
            :metric="data.summary.products_sold"
            :format="formatInteger"
          />
        </template>
        <template v-else>
          <UiSkeleton
            v-for="index in 3"
            :key="index"
            class="h-[116px] rounded-2xl"
          />
        </template>
      </section>

      <UiCard
        title="Ranking de produtos"
        description="Somente transações pagas, com o preço de cada venda"
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
          :row-key="(row: ProductRankingRow) => row.product.id"
          caption="Produtos mais vendidos no período"
          :loading="loading"
          :busy="refreshing"
          :skeleton-rows="ranking.limit.value > 10 ? 10 : ranking.limit.value"
          class="mt-3"
        >
          <template #cell-rank="{ row }">
            <span class="text-xs font-semibold text-ink/50 tabular">{{ row.rank }}º</span>
          </template>
          <template #cell-product="{ row }">
            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
              <NuxtLink
                v-if="!row.product.is_deleted"
                :to="`/products/${row.product.id}`"
                class="font-medium text-ink hover:text-brand-700 hover:underline"
              >
                {{ row.product.name }}
              </NuxtLink>
              <span
                v-else
                class="font-medium text-ink/70"
              >{{ row.product.name }}</span>
              <UiTag
                v-if="row.product.is_deleted"
                size="sm"
              >
                Removido
              </UiTag>
              <StatusBadge
                v-else-if="row.product.status === 'inactive'"
                status="inactive"
                size="sm"
              />
            </div>
            <p
              v-if="row.product.sku"
              class="font-mono text-xs text-ink/50"
            >
              {{ row.product.sku }}
            </p>
            <p class="text-xs text-ink/50 sm:hidden">
              {{ formatInteger(row.units_sold.value) }} un.
            </p>
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
          <template #cell-units_sold="{ row }">
            <div class="flex flex-col items-end gap-0.5">
              <span class="tabular">{{ formatInteger(row.units_sold.value) }}</span>
              <ChangeIndicator
                :metric="row.units_sold"
                size="sm"
              />
            </div>
          </template>
          <template #cell-share="{ row }">
            <div
              class="h-1.5 overflow-hidden rounded-full bg-ink/[0.05]"
              aria-hidden="true"
            >
              <div
                class="h-full rounded-full bg-brand-400"
                :style="{ width: `${Math.max(2, (shares.get(row.product.id) ?? 0) * 100)}%` }"
              />
            </div>
          </template>
          <template #empty>
            <UiEmptyState
              compact
              title="Nenhum produto vendido no período"
              description="Escolha outro período para ver o ranking."
            />
          </template>
        </DataTable>
      </UiCard>
    </template>
  </div>
</template>
