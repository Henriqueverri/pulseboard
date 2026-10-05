<script setup lang="ts">
import { PhCaretRight, PhReceipt } from '@phosphor-icons/vue'
import type { DataColumn } from '~/components/data/DataTable.vue'

useHead({ title: 'Transações · PulseBoard' })

const { list, rows, meta, initialLoading, refreshing, error, refresh } = useTransactions()
const { currency, timezone } = useOrganization()

const columns: DataColumn[] = [
  { key: 'occurred_at', label: 'Data' },
  { key: 'customer', label: 'Cliente', hideBelow: 'md' },
  { key: 'status', label: 'Status', hideBelow: 'sm' },
  { key: 'source', label: 'Origem', hideBelow: 'lg' },
  { key: 'items_count', label: 'Itens', align: 'right', hideBelow: 'xl' },
  { key: 'total_amount', label: 'Total', align: 'right' },
  { key: 'open', label: 'Abrir', srOnlyLabel: true, class: 'w-10' },
]

/** Every row of a `customer_id` search belongs to that customer, removed or not. */
const customerName = computed(() => {
  const id = list.filters.value.customer_id

  return id ? rows.value.find(row => row.customer.id === id)?.customer.name ?? null : null
})
</script>

<template>
  <div>
    <PageHeader
      title="Transações"
      description="Pedidos da organização, gerados pela demo ou recebidos pela API de ingestão. Datas no fuso da organização."
    />

    <UiCard padding="none">
      <TransactionFilters
        :list="list"
        :customer-name="customerName"
      />

      <UiAlert
        v-if="error && rows.length"
        tone="danger"
        class="m-3"
      >
        Não foi possível atualizar a lista: {{ errorMessage(error) }}
      </UiAlert>
      <UiErrorState
        v-if="error && !rows.length"
        :message="errorMessage(error)"
        :retrying="refreshing"
        @retry="refresh()"
      />
      <DataTable
        v-else
        :columns="columns"
        :rows="rows"
        :row-key="row => row.id"
        caption="Lista de transações"
        :loading="initialLoading"
        :busy="refreshing"
      >
        <template #cell-occurred_at="{ row }">
          <NuxtLink
            :to="`/transactions/${row.id}`"
            class="whitespace-nowrap font-medium text-ink tabular hover:underline"
          >
            {{ formatDateTime(row.occurred_at, timezone, { time: true }) }}
          </NuxtLink>
          <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 md:hidden">
            <StatusBadge
              :status="row.status"
              size="sm"
              class="sm:hidden"
            />
            <span class="truncate text-xs text-ink/65">{{ row.customer.name }}</span>
            <UiTag
              v-if="row.customer.is_deleted"
              size="sm"
            >
              Removido
            </UiTag>
          </div>
          <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 lg:hidden">
            <TransactionSourceTag :source="row.source" />
            <span
              v-if="row.external_id"
              class="max-w-[8.5rem] truncate font-mono text-[11px] text-ink/65"
              :title="row.external_id"
            >{{ row.external_id }}</span>
          </div>
        </template>
        <template #cell-source="{ row }">
          <div class="flex min-w-0 flex-col items-start gap-1">
            <TransactionSourceTag :source="row.source" />
            <span
              v-if="row.external_id"
              class="max-w-[180px] truncate font-mono text-[11px] text-ink/65"
              :title="row.external_id"
            >{{ row.external_id }}</span>
          </div>
        </template>
        <template #cell-customer="{ row }">
          <TransactionCustomer
            :customer="row.customer"
            show-email
            class="max-w-[240px]"
          />
        </template>
        <template #cell-status="{ row }">
          <StatusBadge
            :status="row.status"
            size="sm"
          />
        </template>
        <template #cell-items_count="{ row }">
          <span class="text-ink/70 tabular">{{ formatInteger(row.items_count) }}</span>
        </template>
        <template #cell-total_amount="{ row }">
          <span class="whitespace-nowrap font-medium tabular">{{ formatMoney(row.total_amount, currency) }}</span>
        </template>
        <template #cell-open="{ row }">
          <NuxtLink
            :to="`/transactions/${row.id}`"
            class="flex size-8 items-center justify-center rounded-md text-ink/65 hover:bg-ink/[0.05] hover:text-ink"
            :aria-label="`Abrir transação de ${formatDateTime(row.occurred_at, timezone, { time: true })}`"
          >
            <PhCaretRight :size="16" />
          </NuxtLink>
        </template>
        <template #empty>
          <UiEmptyState
            v-if="list.hasActiveFilters.value"
            title="Nenhuma transação encontrada"
            description="Nenhuma transação corresponde aos filtros aplicados."
          >
            <UiButton
              variant="outline"
              size="sm"
              @click="list.clearFilters()"
            >
              Limpar filtros
            </UiButton>
          </UiEmptyState>
          <UiEmptyState
            v-else
            :icon="PhReceipt"
            title="Nenhuma transação registrada"
            description="As transações da organização aparecem aqui assim que forem registradas."
          />
        </template>
      </DataTable>

      <div
        v-if="meta && meta.total > 0"
        class="border-t border-ink/[0.07] px-5 py-3"
      >
        <UiPagination
          :meta="meta"
          :per-page="list.perPage.value"
          :disabled="refreshing"
          @update:page="list.setPage"
          @update:per-page="list.setPerPage"
        />
      </div>
    </UiCard>
  </div>
</template>
