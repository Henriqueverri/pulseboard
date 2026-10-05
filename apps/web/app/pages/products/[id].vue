<script setup lang="ts">
import { PhArrowLeft, PhPencilSimple, PhTrash } from '@phosphor-icons/vue'
import type { Product } from '~/types/product'

const route = useRoute()
const id = computed(() => String(route.params.id))

const { data: product, status, error, refresh } = useProduct(id)
const { currency, timezone } = useOrganization()
const { canDelete } = usePermissions()
const { setDetailLabel } = useBreadcrumbs()
const toast = useToast()

setDetailLabel(() => product.value?.name)
useHead({ title: () => `${product.value?.name ?? 'Produto'} · PulseBoard` })

const notFound = computed(() => asApiError(error.value)?.isNotFound === true)
const formOpen = ref(false)
const deleteOpen = ref(false)

async function onSaved(saved: Product) {
  toast.success('Produto atualizado', saved.name)
  await refresh()
}

async function onDeleted() {
  await navigateTo('/products')
}
</script>

<template>
  <div>
    <UiButton
      to="/products"
      variant="ghost"
      size="sm"
      class="-ml-2 mb-4"
    >
      <template #leading>
        <PhArrowLeft :size="16" />
      </template>
      Produtos
    </UiButton>

    <UiCard
      v-if="notFound"
      padding="none"
    >
      <UiEmptyState
        title="Produto não encontrado"
        description="Ele pode ter sido excluído ou pertencer a outra organização."
      >
        <UiButton
          to="/products"
          variant="outline"
          size="sm"
        >
          Voltar para produtos
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
      v-else-if="!product"
      class="space-y-6"
      aria-busy="true"
    >
      <UiSkeleton class="h-8 w-64" />
      <div class="grid gap-4 sm:grid-cols-2">
        <UiSkeleton class="h-28 rounded-2xl" />
        <UiSkeleton class="h-28 rounded-2xl" />
      </div>
      <UiSkeleton class="h-48 rounded-2xl" />
    </div>

    <template v-else>
      <PageHeader :title="product.name">
        <template #description>
          <span class="inline-flex items-center gap-2">
            <StatusBadge
              :status="product.status"
              size="sm"
            />
            <span v-if="product.sku">SKU <span class="font-mono text-xs">{{ product.sku }}</span></span>
          </span>
        </template>
        <template #actions>
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
        </template>
      </PageHeader>

      <div class="grid gap-4 sm:grid-cols-2">
        <StatCard
          label="Receita total"
          :value="formatMoney(product.revenue, currency)"
        />
        <StatCard
          label="Unidades vendidas"
          :value="formatInteger(product.units_sold)"
        />
      </div>
      <p class="mt-2 text-xs text-ink/65">
        Totais de todo o histórico, considerando somente transações pagas.
      </p>

      <UiCard
        title="Dados do produto"
        class="mt-6"
      >
        <dl class="grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-ink/65">
              Preço atual
            </dt>
            <dd class="mt-0.5 font-medium tabular">
              {{ formatMoney(product.price, currency) }}
            </dd>
          </div>
          <div>
            <dt class="text-ink/65">
              SKU
            </dt>
            <dd class="mt-0.5 font-medium">
              {{ product.sku ?? EMPTY_VALUE }}
            </dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-ink/65">
              ID externo
            </dt>
            <dd class="mt-0.5 break-all font-mono text-xs font-medium">
              {{ product.external_id ?? EMPTY_VALUE }}
            </dd>
          </div>
          <div>
            <dt class="text-ink/65">
              Criado em
            </dt>
            <dd class="mt-0.5 font-medium tabular">
              {{ formatDateTime(product.created_at, timezone, { time: true }) }}
            </dd>
          </div>
          <div>
            <dt class="text-ink/65">
              Atualizado em
            </dt>
            <dd class="mt-0.5 font-medium tabular">
              {{ formatDateTime(product.updated_at, timezone, { time: true }) }}
            </dd>
          </div>
        </dl>
      </UiCard>

      <ProductFormDialog
        v-model:open="formOpen"
        :product="product"
        @saved="onSaved"
      />
      <ProductDeleteDialog
        v-model:open="deleteOpen"
        :product="product"
        @deleted="onDeleted"
      />
    </template>
  </div>
</template>
