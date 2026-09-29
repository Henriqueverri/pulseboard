<script setup lang="ts">
import { PhDotsThree, PhPencilSimple, PhPlus, PhTrash } from '@phosphor-icons/vue'

// Development-only catalog of the UI primitives (removed from production builds in nuxt.config).
definePageMeta({ layout: false, auth: false })

const toast = useToast()
const text = ref('')
const search = ref('')
const price = ref<string | null>('1234.50')
const status = ref<'active' | 'inactive'>('active')
const enabled = ref(true)
const segment = ref<'day' | 'week' | 'month'>('day')
const dialogOpen = ref(false)
const confirmOpen = ref(false)
const drawerOpen = ref(false)
const page = ref(3)
const from = ref('2026-09-01')
const to = ref('2026-09-30')

const menuItems = [
  { label: 'Editar', icon: PhPencilSimple },
  { label: 'Excluir', icon: PhTrash, danger: true, separated: true },
]
</script>

<template>
  <main class="mx-auto max-w-5xl space-y-10 px-6 py-10">
    <header>
      <h1 class="text-2xl font-semibold">
        UI primitives
      </h1>
      <p class="text-sm text-ink/55">
        Catálogo de desenvolvimento (não incluído no build de produção).
      </p>
    </header>

    <section class="space-y-3">
      <h2 class="text-sm font-semibold">
        Button
      </h2>
      <div class="flex flex-wrap items-center gap-2">
        <UiButton variant="primary">
          <template #leading>
            <PhPlus :size="16" />
          </template>
          Primary
        </UiButton>
        <UiButton>Secondary</UiButton>
        <UiButton variant="outline">
          Outline
        </UiButton>
        <UiButton variant="ghost">
          Ghost
        </UiButton>
        <UiButton variant="danger">
          Danger
        </UiButton>
        <UiButton
          variant="primary"
          loading
        >
          Loading
        </UiButton>
        <UiButton disabled>
          Disabled
        </UiButton>
        <UiButton
          size="sm"
          variant="outline"
        >
          Small
        </UiButton>
        <UiButton
          size="lg"
          variant="primary"
        >
          Large
        </UiButton>
        <UiButton
          icon
          variant="ghost"
          aria-label="Mais ações"
        >
          <PhDotsThree :size="18" />
        </UiButton>
      </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2">
      <UiFormField
        v-slot="{ id, describedBy, invalid }"
        label="Nome"
        hint="Como aparece nas listagens."
        required
      >
        <UiInput
          :id="id"
          v-model="text"
          :described-by="describedBy"
          :invalid="invalid"
          placeholder="Mouse sem fio"
        />
      </UiFormField>
      <UiFormField
        v-slot="{ id, describedBy, invalid }"
        label="Preço"
        error="Este campo é obrigatório."
      >
        <UiMoneyInput
          :id="id"
          v-model="price"
          :described-by="describedBy"
          :invalid="invalid"
        />
      </UiFormField>
      <UiSearchInput
        v-model="search"
        placeholder="Buscar produtos"
      />
      <UiSelect
        v-model="status"
        label="Status"
        :options="[{ value: 'active', label: 'Ativo' }, { value: 'inactive', label: 'Inativo' }]"
      />
      <div class="flex items-center gap-3">
        <UiSwitch
          v-model="enabled"
          label="Ativo"
        />
        <span class="text-sm">Ativo: {{ enabled }} · preço: {{ price }} · busca: "{{ search }}"</span>
      </div>
      <UiDateRangeInput
        v-model:from="from"
        v-model:to="to"
      />
    </section>

    <section class="space-y-3">
      <h2 class="text-sm font-semibold">
        Tags, badges, variação
      </h2>
      <div class="flex flex-wrap items-center gap-2">
        <UiTag
          tone="success"
          dot
        >
          Pago
        </UiTag>
        <UiTag
          tone="warning"
          dot
        >
          Pendente
        </UiTag>
        <UiTag
          tone="info"
          dot
        >
          Reembolsado
        </UiTag>
        <UiTag
          tone="danger"
          dot
        >
          Cancelado
        </UiTag>
        <UiTag>Removido</UiTag>
        <UiBadge>3</UiBadge>
        <UiBadge tone="brand">
          12
        </UiBadge>
        <ChangeIndicator :metric="{ value: '1250.00', previous: '1000.00', change: 25 }" />
        <ChangeIndicator :metric="{ value: 6, previous: 9, change: -33.3 }" />
        <ChangeIndicator :metric="{ value: 0, previous: 0, change: 0 }" />
        <ChangeIndicator :metric="{ value: '480.00', previous: '0.00', change: null }" />
        <ChangeIndicator :metric="{ value: null, previous: '10.00', change: null }" />
        <ChangeIndicator
          :metric="{ value: 4, previous: 2, change: 100 }"
          polarity="negative"
        />
        <UiAvatar name="Maria Souza" />
        <UiAvatar name="João Lima" />
      </div>
      <UiSegmentedControl
        v-model="segment"
        label="Granularidade"
        :options="[{ value: 'day', label: 'Dia' }, { value: 'week', label: 'Semana' }, { value: 'month', label: 'Mês' }]"
      />
    </section>

    <section class="grid gap-4 sm:grid-cols-2">
      <UiCard
        title="Card"
        description="Com descrição e ações"
      >
        <template #actions>
          <UiDropdownMenu :items="menuItems">
            <UiButton
              icon
              size="sm"
              variant="ghost"
              aria-label="Ações"
            >
              <PhDotsThree :size="18" />
            </UiButton>
          </UiDropdownMenu>
        </template>
        <div class="space-y-2">
          <UiSkeleton class="h-4 w-2/3" />
          <UiSkeleton class="h-4 w-1/2" />
        </div>
      </UiCard>
      <UiCard padding="none">
        <UiEmptyState
          title="Nenhum produto"
          description="Crie o primeiro produto para começar."
          compact
        >
          <UiButton
            size="sm"
            variant="primary"
          >
            Criar produto
          </UiButton>
        </UiEmptyState>
      </UiCard>
      <UiCard padding="none">
        <UiErrorState
          compact
          message="Verifique sua conexão."
        />
      </UiCard>
      <div class="space-y-2">
        <UiAlert
          tone="danger"
          title="E-mail ou senha incorretos."
        >
          Confira os dados e tente novamente.
        </UiAlert>
        <UiAlert tone="info">
          Métricas consideram somente transações pagas.
        </UiAlert>
      </div>
    </section>

    <section class="space-y-3">
      <h2 class="text-sm font-semibold">
        Overlays
      </h2>
      <div class="flex flex-wrap gap-2">
        <UiButton @click="dialogOpen = true">
          Dialog
        </UiButton>
        <UiButton @click="confirmOpen = true">
          Confirm
        </UiButton>
        <UiButton @click="drawerOpen = true">
          Drawer
        </UiButton>
        <UiButton @click="toast.success('Produto criado', 'Mouse sem fio foi adicionado.')">
          Toast
        </UiButton>
        <UiTooltip content="Tooltip de exemplo">
          <UiButton variant="outline">
            Tooltip
          </UiButton>
        </UiTooltip>
      </div>
      <UiPagination
        :meta="{ current_page: page, last_page: 20, total: 300, from: (page - 1) * 15 + 1, to: page * 15 }"
        @update:page="page = $event"
      />
    </section>

    <UiDialog
      v-model:open="dialogOpen"
      title="Novo produto"
      description="Preencha os dados do produto."
    >
      <p class="text-sm">
        Conteúdo do dialog.
      </p>
      <template #footer>
        <UiButton
          variant="outline"
          @click="dialogOpen = false"
        >
          Cancelar
        </UiButton>
        <UiButton variant="primary">
          Salvar
        </UiButton>
      </template>
    </UiDialog>
    <UiConfirmDialog
      v-model:open="confirmOpen"
      title="Excluir produto?"
      description="Produtos com vendas são arquivados e o histórico é preservado."
      confirm-label="Excluir"
      tone="danger"
      @confirm="confirmOpen = false"
    />
    <UiDrawer
      v-model:open="drawerOpen"
      title="Filtros"
    >
      <div class="p-5 text-sm">
        Conteúdo do drawer.
      </div>
    </UiDrawer>
  </main>
</template>
