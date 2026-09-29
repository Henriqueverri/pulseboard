<script setup lang="ts">
import { PhArrowLeft, PhPencilSimple, PhTrash } from '@phosphor-icons/vue'
import type { Customer } from '~/types/customer'

const route = useRoute()
const id = computed(() => String(route.params.id))

const { data: customer, status, error, refresh } = useCustomer(id)
const { currency, timezone } = useOrganization()
const { canDelete } = usePermissions()
const { setDetailLabel } = useBreadcrumbs()
const toast = useToast()

setDetailLabel(() => customer.value?.name)
useHead({ title: () => `${customer.value?.name ?? 'Cliente'} · PulseBoard` })

const notFound = computed(() => asApiError(error.value)?.isNotFound === true)
const formOpen = ref(false)
const deleteOpen = ref(false)

async function onSaved(saved: Customer) {
  toast.success('Cliente atualizado', saved.name)
  await refresh()
}
</script>

<template>
  <div>
    <UiButton
      to="/customers"
      variant="ghost"
      size="sm"
      class="-ml-2 mb-4"
    >
      <template #leading>
        <PhArrowLeft :size="16" />
      </template>
      Clientes
    </UiButton>

    <UiCard
      v-if="notFound"
      padding="none"
    >
      <UiEmptyState
        title="Cliente não encontrado"
        description="Ele pode ter sido excluído ou pertencer a outra organização."
      >
        <UiButton
          to="/customers"
          variant="outline"
          size="sm"
        >
          Voltar para clientes
        </UiButton>
      </UiEmptyState>
    </UiCard>

    <UiCard
      v-else-if="error"
      padding="none"
    >
      <UiErrorState
        :message="errorMessage(error)"
        :retrying="status === 'pending'"
        @retry="refresh()"
      />
    </UiCard>

    <div
      v-else-if="!customer"
      class="space-y-6"
      aria-busy="true"
    >
      <UiSkeleton class="h-12 w-72" />
      <div class="grid gap-4 sm:grid-cols-2">
        <UiSkeleton class="h-28 rounded-2xl" />
        <UiSkeleton class="h-28 rounded-2xl" />
      </div>
      <UiSkeleton class="h-64 rounded-2xl" />
    </div>

    <template v-else>
      <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 items-center gap-4">
          <UiAvatar
            :name="customer.name"
            size="lg"
          />
          <div class="min-w-0">
            <h1 class="truncate text-xl font-semibold tracking-tight text-ink sm:text-2xl">
              {{ customer.name }}
            </h1>
            <p class="truncate text-sm text-ink/55">
              {{ customer.email }} · cliente desde {{ formatDateTime(customer.created_at, timezone, { time: false }) }}
            </p>
          </div>
        </div>
        <div class="flex shrink-0 gap-2">
          <UiButton
            variant="outline"
            @click="formOpen = true"
          >
            <template #leading>
              <PhPencilSimple :size="16" />
            </template>
            Editar
          </UiButton>
          <UiButton
            v-if="canDelete"
            variant="outline"
            class="text-danger-strong"
            @click="deleteOpen = true"
          >
            <template #leading>
              <PhTrash :size="16" />
            </template>
            Excluir
          </UiButton>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <StatCard
          label="Pedidos pagos"
          :value="formatInteger(customer.orders_count)"
        />
        <StatCard
          label="Total gasto"
          :value="formatMoney(customer.total_spent, currency)"
        />
      </div>
      <p class="mt-2 text-xs text-ink/50">
        Totais de todo o histórico, considerando somente transações pagas.
      </p>

      <CustomerRecentTransactions
        class="mt-6"
        :customer-id="customer.id"
        :transactions="customer.recent_transactions"
      />

      <CustomerFormDialog
        v-model:open="formOpen"
        :customer="customer"
        @saved="onSaved"
      />
      <CustomerDeleteDialog
        v-model:open="deleteOpen"
        :customer="customer"
        @deleted="navigateTo('/customers')"
      />
    </template>
  </div>
</template>
