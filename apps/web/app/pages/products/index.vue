<script setup lang="ts">
import { PhDotsThree, PhPackage, PhPencilSimple, PhPlus, PhTrash } from '@phosphor-icons/vue'
import type { DataColumn } from '~/components/data/DataTable.vue'
import type { MenuItem } from '~/components/ui/DropdownMenu.vue'
import type { Product, ProductStatus } from '~/types/product'

useHead({ title: 'Produtos · PulseBoard' })

const { list, rows, meta, initialLoading, refreshing, error, refresh } = useProducts()
const { currency } = useOrganization()
const { canDelete } = usePermissions()
const toast = useToast()

const statusOptions = [
  { value: 'all', label: 'Todos os status' },
  { value: 'active', label: 'Ativos' },
  { value: 'inactive', label: 'Inativos' },
]

const search = computed({
  get: () => list.filters.value.q ?? '',
  set: value => list.setFilter('q', value || undefined),
})

const status = computed({
  get: () => list.filters.value.status ?? 'all',
  set: value => list.setFilter('status', value === 'all' ? undefined : value as ProductStatus),
})

const columns: DataColumn[] = [
  { key: 'name', label: 'Produto' },
  { key: 'sku', label: 'SKU', hideBelow: 'md' },
  { key: 'price', label: 'Preço', align: 'right' },
  { key: 'status', label: 'Status', hideBelow: 'sm' },
  { key: 'actions', label: 'Ações', srOnlyLabel: true, align: 'right', class: 'w-12' },
]

const formOpen = ref(false)
const editing = ref<Product | null>(null)
const deleteOpen = ref(false)
const deleting = ref<Product | null>(null)

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function rowActions(product: Product): MenuItem[] {
  const items: MenuItem[] = [
    {
      label: 'Editar',
      icon: PhPencilSimple,
      onSelect: () => {
        editing.value = product
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
        deleting.value = product
        deleteOpen.value = true
      },
    })
  }

  return items
}

async function onSaved(product: Product, mode: 'created' | 'updated') {
  toast.success(mode === 'created' ? 'Produto criado' : 'Produto atualizado', product.name)
  await refresh()
}
</script>

<template>
  <div>
    <PageHeader
      title="Produtos"
      description="Catálogo de produtos da organização."
    >
      <template #actions>
        <UiButton
          variant="primary"
          @click="openCreate"
        >
          <template #leading>
            <PhPlus :size="16" />
          </template>
          Novo produto
        </UiButton>
      </template>
    </PageHeader>

    <UiCard padding="none">
      <div class="flex flex-col gap-2 border-b border-ink/[0.07] p-3 sm:flex-row sm:items-center">
        <UiSearchInput
          v-model="search"
          placeholder="Buscar por nome ou SKU"
          label="Buscar produtos"
          class="sm:max-w-xs"
        />
        <UiSelect
          v-model="status"
          :options="statusOptions"
          label="Filtrar por status"
          class="sm:w-44"
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
        caption="Lista de produtos"
        :loading="initialLoading"
        :busy="refreshing"
      >
        <template #cell-name="{ row }">
          <NuxtLink
            :to="`/products/${row.id}`"
            class="font-medium text-ink hover:underline"
          >
            {{ row.name }}
          </NuxtLink>
          <p class="mt-0.5 flex items-center gap-2 text-xs text-ink/50 md:hidden">
            <span>{{ row.sku ?? 'Sem SKU' }}</span>
            <StatusBadge
              v-if="row.status === 'inactive'"
              :status="row.status"
              size="sm"
              class="sm:hidden"
            />
          </p>
        </template>
        <template #cell-sku="{ row }">
          <span
            v-if="row.sku"
            class="font-mono text-xs text-ink/70"
          >{{ row.sku }}</span>
          <span
            v-else
            class="text-ink/35"
          >{{ EMPTY_VALUE }}</span>
        </template>
        <template #cell-price="{ row }">
          <span class="tabular">{{ formatMoney(row.price, currency) }}</span>
        </template>
        <template #cell-status="{ row }">
          <StatusBadge
            :status="row.status"
            size="sm"
          />
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
            title="Nenhum produto encontrado"
            description="Tente outros termos ou remova os filtros."
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
            :icon="PhPackage"
            title="Nenhum produto cadastrado"
            description="Cadastre produtos para registrar vendas e acompanhar o desempenho."
          >
            <UiButton
              variant="primary"
              size="sm"
              @click="openCreate"
            >
              Criar primeiro produto
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

    <ProductFormDialog
      v-model:open="formOpen"
      :product="editing"
      @saved="onSaved"
    />
    <ProductDeleteDialog
      v-model:open="deleteOpen"
      :product="deleting"
      @deleted="refresh()"
    />
  </div>
</template>
