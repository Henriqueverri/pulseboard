<script setup lang="ts">
import type { InventoryResponse } from '~/types/analytics'

const LIMIT = 5

const props = defineProps<{
  response: InventoryResponse | null | undefined
  loading: boolean
  refreshing: boolean
  error: unknown
}>()

const emit = defineEmits<{ retry: [] }>()

const summary = computed(() => props.response?.data.summary)
const windowDays = computed(() => props.response?.meta.sales_window.days ?? 28)
const attention = computed(() => itemsNeedingAttention(props.response?.data.items ?? []))
const shown = computed(() => attention.value.slice(0, LIMIT))
const hidden = computed(() => attention.value.length - shown.value.length)
</script>

<template>
  <DataCard
    title="Estoque"
    :description="`Baixos ou zerados · duração pela média de ${windowDays} dias`"
    :loading="loading || (!response && !error)"
    :refreshing="refreshing"
    :error="error"
    :empty="Boolean(response) && attention.length === 0"
    empty-title="Todo o estoque acima do mínimo"
    :empty-description="summary ? `${formatInteger(summary.tracked)} ${summary.tracked === 1 ? 'produto' : 'produtos'} com controle de estoque.` : undefined"
    @retry="emit('retry')"
  >
    <template #actions>
      <div
        v-if="summary"
        class="flex items-center gap-2"
        data-testid="stock-summary"
      >
        <UiTag
          v-if="summary.out > 0"
          tone="danger"
          size="sm"
        >
          {{ formatInteger(summary.out) }} {{ summary.out === 1 ? 'zerado' : 'zerados' }}
        </UiTag>
        <UiTag
          v-if="summary.low > 0"
          tone="warning"
          size="sm"
        >
          {{ formatInteger(summary.low) }} {{ summary.low === 1 ? 'baixo' : 'baixos' }}
        </UiTag>
      </div>
    </template>

    <ul
      class="flex flex-col divide-y divide-ink/[0.06]"
      aria-label="Produtos com estoque baixo ou zerado"
    >
      <li
        v-for="item in shown"
        :key="item.product.id"
        class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
      >
        <div class="min-w-0">
          <NuxtLink
            :to="`/products/${item.product.id}`"
            class="block truncate text-sm font-medium text-ink hover:text-brand-700 hover:underline"
          >
            {{ item.product.name }}
          </NuxtLink>
          <p class="truncate text-xs text-ink/65">
            {{ formatDaysOfCover(item.days_of_cover, windowDays) }}
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-3">
          <span class="text-right text-sm tabular">
            <span class="font-semibold text-ink">{{ formatUnits(item.quantity) }}</span>
            <span class="block text-xs text-ink/65">mín. {{ formatInteger(item.min_quantity) }}</span>
          </span>
          <StockStatusTag
            :status="item.status"
            size="sm"
          />
        </div>
      </li>
    </ul>
    <p
      v-if="hidden > 0"
      class="mt-3 text-xs text-ink/65"
    >
      E mais {{ formatInteger(hidden) }} {{ hidden === 1 ? 'produto' : 'produtos' }} precisando de reposição.
    </p>
  </DataCard>
</template>
