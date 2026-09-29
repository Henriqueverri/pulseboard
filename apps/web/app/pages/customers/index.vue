<script setup lang="ts">
import { PhDotsThree, PhPencilSimple, PhPlus, PhTrash, PhUsers } from '@phosphor-icons/vue'
import type { DataColumn } from '~/components/data/DataTable.vue'
import type { MenuItem } from '~/components/ui/DropdownMenu.vue'
import type { Customer } from '~/types/customer'

useHead({ title: 'Clientes · PulseBoard' })

const { list, rows, meta, initialLoading, refreshing, error, refresh } = useCustomers()
const { timezone } = useOrganization()
const { canDelete } = usePermissions()
const toast = useToast()

const search = computed({
  get: () => list.filters.value.q ?? '',
  set: value => list.setFilter('q', value || undefined),
})

const columns: DataColumn[] = [
  { key: 'name', label: 'Cliente' },
  { key: 'email', label: 'E-mail', hideBelow: 'md' },
  { key: 'created_at', label: 'Cliente desde', hideBelow: 'lg' },
  { key: 'actions', label: 'Ações', srOnlyLabel: true, align: 'right', class: 'w-12' },
]

const formOpen = ref(false)
const editing = ref<Customer | null>(null)
const deleteOpen = ref(false)
const deleting = ref<Customer | null>(null)

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function rowActions(customer: Customer): MenuItem[] {
  const items: MenuItem[] = [
    {
      label: 'Editar',
      icon: PhPencilSimple,
      onSelect: () => {
        editing.value = customer
        formOpen.value = true
      },
    },
  ]

  if (canDelete.value) {
    items.push({
      label: 'Excluir',
      icon: PhTrash,
      danger: true,
      separated: true,
      onSelect: () => {
        deleting.value = customer
        deleteOpen.value = true
      },
    })
  }

  return items
}

async function onSaved(customer: Customer, mode: 'created' | 'updated') {
  toast.success(mode === 'created' ? 'Cliente criado' : 'Cliente atualizado', customer.name)
  await refresh()
}
</script>

<template>
  <div>
    <PageHeader
      title="Clientes"
      description="Clientes que compram da organização."
    >
      <template #actions>
        <UiButton
          variant="primary"
          @click="openCreate"
        >
          <template #leading>
            <PhPlus :size="16" />
          </template>
          Novo cliente
        </UiButton>
      </template>
    </PageHeader>

    <UiCard padding="none">
      <div class="border-b border-ink/[0.07] p-3">
        <UiSearchInput
          v-model="search"
          placeholder="Buscar por nome ou e-mail"
          label="Buscar clientes"
          class="sm:max-w-xs"
        />
      </div>

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
        caption="Lista de clientes"
        :loading="initialLoading"
        :busy="refreshing"
      >
        <template #cell-name="{ row }">
          <div class="flex min-w-0 items-center gap-3">
            <UiAvatar
              :name="row.name"
              size="sm"
            />
            <div class="min-w-0">
              <NuxtLink
                :to="`/customers/${row.id}`"
                class="block truncate font-medium text-ink hover:underline"
              >
                {{ row.name }}
              </NuxtLink>
              <p class="truncate text-xs text-ink/65 md:hidden">
                {{ row.email }}
              </p>
            </div>
          </div>
        </template>
        <template #cell-email="{ row }">
          <span class="text-ink/70">{{ row.email }}</span>
        </template>
        <template #cell-created_at="{ row }">
          <span class="text-ink/70 tabular">{{ formatDateTime(row.created_at, timezone, { time: false }) }}</span>
        </template>
        <template #cell-actions="{ row }">
          <UiDropdownMenu :items="rowActions(row)">
            <UiButton
              icon
              size="sm"
              variant="ghost"
              :aria-label="`Ações de ${row.name}`"
            >
              <PhDotsThree
                :size="18"
                weight="bold"
              />
            </UiButton>
          </UiDropdownMenu>
        </template>
        <template #empty>
          <UiEmptyState
            v-if="list.hasActiveFilters.value"
            title="Nenhum cliente encontrado"
            description="Tente outro nome ou e-mail."
          >
            <UiButton
              variant="outline"
              size="sm"
              @click="list.clearFilters()"
            >
              Limpar busca
            </UiButton>
          </UiEmptyState>
          <UiEmptyState
            v-else
            :icon="PhUsers"
            title="Nenhum cliente cadastrado"
            description="Cadastre clientes para registrar vendas e acompanhar quem mais compra."
          >
            <UiButton
              variant="primary"
              size="sm"
              @click="openCreate"
            >
              Criar primeiro cliente
            </UiButton>
          </UiEmptyState>
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

    <CustomerFormDialog
      v-model:open="formOpen"
      :customer="editing"
      @saved="onSaved"
    />
    <CustomerDeleteDialog
      v-model:open="deleteOpen"
      :customer="deleting"
      @deleted="refresh()"
    />
  </div>
</template>
