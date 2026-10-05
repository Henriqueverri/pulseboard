<script setup lang="ts">
import { PhFunnelSimple, PhX } from '@phosphor-icons/vue'
import type { ListQuery } from '~/composables/useListQuery'
import type { TransactionFilters } from '~/composables/useTransactions'
import { TRANSACTION_STATUS_LABELS, TRANSACTION_STATUSES } from '~/types/transaction'
import type { TransactionStatus } from '~/types/transaction'

const props = defineProps<{
  list: ListQuery<TransactionFilters>
  /** Name of the `customer_id` customer, when known from the loaded rows. */
  customerName?: string | null
}>()

const { timezone } = useOrganization()
const sheetOpen = ref(false)

const statusOptions = [
  { value: 'all', label: 'Todos os status' },
  ...TRANSACTION_STATUSES.map(value => ({ value, label: TRANSACTION_STATUS_LABELS[value] })),
]

const filters = computed(() => props.list.filters.value)

const search = computed({
  get: () => filters.value.q ?? '',
  set: value => props.list.setFilter('q', value || undefined),
})

const status = computed({
  get: () => filters.value.status ?? 'all',
  set: value => props.list.setFilter('status', value === 'all' ? undefined : value as TransactionStatus),
})

const from = computed({
  get: () => filters.value.from ?? '',
  set: value => props.list.setFilter('from', value || undefined),
})

const to = computed({
  get: () => filters.value.to ?? '',
  set: value => props.list.setFilter('to', value || undefined),
})

const today = computed(() => todayIn(timezone.value))

/** Filters that live in the sheet on phones (search stays visible). */
const sheetFilterCount = computed(() => [filters.value.status, filters.value.from, filters.value.to].filter(Boolean).length)
</script>

<template>
  <div class="flex flex-col gap-2 border-b border-ink/[0.07] p-3">
    <div class="flex flex-col gap-2 md:flex-row md:items-center">
      <div class="flex gap-2 md:contents">
        <UiSearchInput
          v-model="search"
          placeholder="ID, ID externo, cliente ou e-mail"
          label="Buscar transações"
          class="md:max-w-xs"
        />
        <UiButton
          variant="outline"
          class="md:hidden"
          :aria-label="`Filtros${sheetFilterCount ? ` (${sheetFilterCount} ativos)` : ''}`"
          @click="sheetOpen = true"
        >
          <template #leading>
            <PhFunnelSimple :size="16" />
          </template>
          Filtros
          <UiBadge
            v-if="sheetFilterCount"
            tone="brand"
          >
            {{ sheetFilterCount }}
          </UiBadge>
        </UiButton>
      </div>
      <div class="hidden md:block md:w-44">
        <UiSelect
          v-model="status"
          :options="statusOptions"
          label="Filtrar por status"
        />
      </div>
      <div class="hidden md:block">
        <UiDateRangeInput
          v-model:from="from"
          v-model:to="to"
          :max="today"
          from-label="Transações a partir de"
          to-label="Transações até"
        />
      </div>
    </div>

    <div
      v-if="filters.customer_id"
      class="flex flex-wrap items-center gap-2"
    >
      <span class="inline-flex h-7 items-center gap-1.5 rounded-full bg-brand-50 pl-3 pr-1 text-xs font-medium text-brand-700">
        Cliente: {{ customerName ?? 'selecionado' }}
        <button
          type="button"
          class="flex size-5 items-center justify-center rounded-full hover:bg-brand-100"
          aria-label="Remover filtro de cliente"
          @click="list.setFilter('customer_id', undefined)"
        >
          <PhX
            :size="12"
            weight="bold"
          />
        </button>
      </span>
    </div>

    <UiDrawer
      v-model:open="sheetOpen"
      side="bottom"
      title="Filtros"
    >
      <div class="space-y-4 p-5">
        <UiFormField
          v-slot="{ id }"
          label="Status"
        >
          <UiSelect
            :id="id"
            v-model="status"
            :options="statusOptions"
          />
        </UiFormField>
        <div class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-ink">Período</span>
          <UiDateRangeInput
            v-model:from="from"
            v-model:to="to"
            :max="today"
            from-label="Transações a partir de"
            to-label="Transações até"
          />
        </div>
      </div>
      <template #footer>
        <UiButton
          variant="outline"
          class="flex-1"
          @click="list.clearFilters()"
        >
          Limpar filtros
        </UiButton>
        <UiButton
          variant="primary"
          class="flex-1"
          @click="sheetOpen = false"
        >
          Ver resultados
        </UiButton>
      </template>
    </UiDrawer>
  </div>
</template>
