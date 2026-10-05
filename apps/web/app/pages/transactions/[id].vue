<script setup lang="ts">
import { PhArrowLeft } from '@phosphor-icons/vue'
import type { DataColumn } from '~/components/data/DataTable.vue'
import { TRANSACTION_SOURCE_DESCRIPTIONS } from '~/types/transaction'

const route = useRoute()
const id = computed(() => String(route.params.id))

const { data: transaction, status, error, refresh } = useTransaction(id)
const { currency, timezone } = useOrganization()
const { setDetailLabel } = useBreadcrumbs()
const toast = useToast()

const occurredAt = computed(() => transaction.value ? formatDateTime(transaction.value.occurred_at, timezone.value, { time: true }) : null)

setDetailLabel(occurredAt)
useHead({ title: () => `Transação ${occurredAt.value ?? ''} · PulseBoard`.replace('  ', ' ') })

const notFound = computed(() => asApiError(error.value)?.isNotFound === true)

const columns: DataColumn[] = [
  { key: 'product', label: 'Produto' },
  { key: 'quantity', label: 'Qtd.', align: 'right' },
  { key: 'unit_price', label: 'Preço unitário', align: 'right', hideBelow: 'sm' },
  { key: 'line_total', label: 'Total', align: 'right' },
]

function copyFailed() {
  toast.error('Não foi possível copiar. Selecione o texto e copie manualmente.')
}
</script>

<template>
  <div>
    <UiButton
      to="/transactions"
      variant="ghost"
      size="sm"
      class="-ml-2 mb-4"
    >
      <template #leading>
        <PhArrowLeft :size="16" />
      </template>
      Transações
    </UiButton>

    <UiCard
      v-if="notFound"
      padding="none"
    >
      <UiEmptyState
        title="Transação não encontrada"
        description="Ela pode pertencer a outra organização ou o endereço está incorreto."
      >
        <UiButton
          to="/transactions"
          variant="outline"
          size="sm"
        >
          Voltar para transações
        </UiButton>
      </UiEmptyState>
    </UiCard>

    <UiCard
      v-else-if="error"
      padding="none"
    >
      <UiErrorState
        :message="errorMessage(error)"
        :request-id="asApiError(error)?.requestId"
        :retrying="status === 'pending'"
        @retry="refresh()"
      />
    </UiCard>

    <div
      v-else-if="!transaction"
      class="space-y-6"
      aria-busy="true"
    >
      <UiSkeleton class="h-10 w-72" />
      <div class="grid gap-4 lg:grid-cols-3">
        <UiSkeleton class="h-64 rounded-2xl lg:col-span-2" />
        <UiSkeleton class="h-64 rounded-2xl" />
      </div>
    </div>

    <template v-else>
      <PageHeader :title="formatMoney(transaction.total_amount, currency)">
        <template #description>
          <span class="inline-flex flex-wrap items-center gap-2">
            <StatusBadge
              :status="transaction.status"
              size="sm"
            />
            <TransactionSourceTag :source="transaction.source" />
            <span class="tabular">{{ occurredAt }}</span>
          </span>
        </template>
      </PageHeader>

      <!-- Below lg the column wrappers dissolve (display: contents) so `order` puts the lifecycle first. -->
      <div class="flex flex-col gap-4 lg:grid lg:grid-cols-3 lg:items-start">
        <div class="contents lg:col-span-2 lg:flex lg:flex-col lg:gap-4">
          <UiCard
            title="Itens"
            :description="`${formatInteger(transaction.items.length)} ${transaction.items.length === 1 ? 'item' : 'itens'} · preços no momento da venda`"
            padding="none"
            class="order-2"
          >
            <DataTable
              :columns="columns"
              :rows="transaction.items"
              :row-key="row => row.id"
              caption="Itens da transação"
            >
              <template #cell-product="{ row }">
                <div class="flex min-w-0 items-center gap-2">
                  <span
                    v-if="row.product.is_deleted"
                    class="truncate font-medium text-ink/70"
                  >{{ row.product.name }}</span>
                  <NuxtLink
                    v-else
                    :to="`/products/${row.product.id}`"
                    class="truncate font-medium text-ink hover:underline"
                  >
                    {{ row.product.name }}
                  </NuxtLink>
                  <UiTag
                    v-if="row.product.is_deleted"
                    size="sm"
                  >
                    Removido
                  </UiTag>
                </div>
                <p class="text-xs text-ink/65">
                  <span class="font-mono">{{ row.product.sku ?? 'Sem SKU' }}</span>
                  <span class="sm:hidden"> · {{ formatMoney(row.unit_price, currency) }} cada</span>
                </p>
              </template>
              <template #cell-quantity="{ row }">
                <span class="tabular">{{ formatInteger(row.quantity) }}</span>
              </template>
              <template #cell-unit_price="{ row }">
                <span class="tabular text-ink/70">{{ formatMoney(row.unit_price, currency) }}</span>
              </template>
              <template #cell-line_total="{ row }">
                <span class="whitespace-nowrap font-medium tabular">{{ formatMoney(row.line_total, currency) }}</span>
              </template>
              <template #empty>
                <UiEmptyState
                  title="Sem itens"
                  description="Esta transação não tem itens registrados."
                  compact
                />
              </template>
            </DataTable>
            <template #footer>
              <div class="flex w-full items-center justify-between text-sm">
                <span class="text-ink/65">Total da transação</span>
                <span class="text-base font-semibold tabular">{{ formatMoney(transaction.total_amount, currency) }}</span>
              </div>
            </template>
          </UiCard>

          <UiCard
            title="Identificação e origem"
            class="order-3"
          >
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
              <div>
                <dt class="text-xs text-ink/65">
                  Origem
                </dt>
                <dd class="mt-1 space-y-1">
                  <TransactionSourceTag
                    :source="transaction.source"
                    size="md"
                  />
                  <p class="text-xs text-ink/65">
                    {{ TRANSACTION_SOURCE_DESCRIPTIONS[transaction.source] }}
                  </p>
                </dd>
              </div>
              <div>
                <dt class="text-xs text-ink/65">
                  ID externo
                </dt>
                <dd class="mt-1">
                  <div
                    v-if="transaction.external_id"
                    class="flex min-w-0 items-center gap-1"
                  >
                    <span class="truncate font-mono text-xs text-ink">{{ transaction.external_id }}</span>
                    <UiCopyButton
                      :value="transaction.external_id"
                      label="Copiar ID externo"
                      @failed="copyFailed"
                    />
                  </div>
                  <p
                    v-else
                    class="text-xs text-ink/65"
                  >
                    Sem ID externo: transações da demo não vêm de um sistema integrado.
                  </p>
                </dd>
              </div>
              <div class="sm:col-span-2">
                <dt class="text-xs text-ink/65">
                  ID no PulseBoard
                </dt>
                <dd class="mt-1 flex min-w-0 items-center gap-1">
                  <span class="truncate font-mono text-xs text-ink">{{ transaction.id }}</span>
                  <UiCopyButton
                    :value="transaction.id"
                    label="Copiar ID da transação"
                    @failed="copyFailed"
                  />
                </dd>
              </div>
            </dl>
            <p
              v-if="transaction.external_id"
              class="mt-4 rounded-lg bg-ink/[0.03] px-3 py-2 text-xs text-ink/65"
            >
              O sistema de origem consulta esta transação por
              <code class="break-all font-mono text-ink">GET /ingest/transactions/{{ transaction.external_id }}</code>
              e reenviar o mesmo <code class="font-mono text-ink">external_id</code> não cria uma duplicata.
            </p>
          </UiCard>
        </div>

        <div class="contents lg:flex lg:flex-col lg:gap-4">
          <UiCard
            title="Ciclo de vida"
            description="Histórico de status registrado pela API, na ordem em que aconteceu."
            class="order-1"
          >
            <TransactionStatusHistory
              :history="transaction.status_history"
              :timezone="timezone"
            />
          </UiCard>

          <UiCard
            title="Cliente"
            class="order-4"
          >
            <div class="flex items-center gap-3">
              <UiAvatar :name="transaction.customer.name" />
              <TransactionCustomer
                :customer="transaction.customer"
                show-email
              />
            </div>
            <p
              v-if="transaction.customer.is_deleted"
              class="mt-3 text-xs text-ink/65"
            >
              O cliente foi removido; a transação preserva o histórico.
            </p>
          </UiCard>
        </div>
      </div>
    </template>
  </div>
</template>
