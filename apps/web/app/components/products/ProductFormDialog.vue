<script setup lang="ts">
import type { Product, ProductInput } from '~/types/product'

const props = withDefaults(defineProps<{
  /** `null` creates a product. */
  product?: Product | null
}>(), {
  product: null,
})

const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ saved: [product: Product, mode: 'created' | 'updated'] }>()

const mutations = useProductMutations()
const { fieldErrors, formError, capture, clear, reset, setFieldError } = useFormErrors(
  ['name', 'sku', 'price', 'status'] as const,
)

const form = reactive({ name: '', sku: '', price: null as string | null, active: true })
const pending = ref(false)
const isEdit = computed(() => props.product !== null)

watch(open, (value) => {
  if (!value) {
    return
  }

  reset()
  form.name = props.product?.name ?? ''
  form.sku = props.product?.sku ?? ''
  form.price = props.product?.price ?? null
  form.active = (props.product?.status ?? 'active') === 'active'
}, { immediate: true })

function toInput(): ProductInput | null {
  if (form.price === null) {
    setFieldError('price', 'Informe um preço válido, por exemplo 1.234,50.')

    return null
  }

  return {
    name: form.name.trim(),
    sku: form.sku.trim() || null,
    price: form.price,
    status: form.active ? 'active' : 'inactive',
  }
}

async function onSubmit() {
  reset()
  const input = toInput()
  if (!input) {
    return
  }

  pending.value = true
  try {
    if (props.product) {
      const changes = changedFields(props.product, input)
      if (Object.keys(changes).length === 0) {
        open.value = false

        return
      }
      emit('saved', await mutations.update(props.product, input), 'updated')
    }
    else {
      emit('saved', await mutations.create(input), 'created')
    }
    open.value = false
  }
  catch (error) {
    capture(error, 'Não foi possível salvar o produto. Tente novamente.')
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <UiDialog
    v-model:open="open"
    :title="isEdit ? 'Editar produto' : 'Novo produto'"
    :description="isEdit ? undefined : 'O produto fica disponível para novas transações.'"
    :persistent="pending"
  >
    <form
      id="product-form"
      class="space-y-4"
      novalidate
      @submit.prevent="onSubmit"
    >
      <UiAlert
        v-if="formError"
        tone="danger"
      >
        {{ formError }}
      </UiAlert>

      <UiFormField
        v-slot="{ id, describedBy, invalid }"
        label="Nome"
        required
        :error="fieldErrors.name"
      >
        <UiInput
          :id="id"
          v-model="form.name"
          maxlength="255"
          autocomplete="off"
          :invalid="invalid"
          :described-by="describedBy"
          @update:model-value="clear('name')"
        />
      </UiFormField>

      <div class="grid gap-4 sm:grid-cols-2">
        <UiFormField
          v-slot="{ id, describedBy, invalid }"
          label="SKU"
          optional
          hint="Único na organização."
          :error="fieldErrors.sku"
        >
          <UiInput
            :id="id"
            v-model="form.sku"
            maxlength="64"
            autocomplete="off"
            spellcheck="false"
            :invalid="invalid"
            :described-by="describedBy"
            @update:model-value="clear('sku')"
          />
        </UiFormField>

        <UiFormField
          v-slot="{ id, describedBy, invalid }"
          label="Preço"
          required
          :error="fieldErrors.price"
        >
          <UiMoneyInput
            :id="id"
            v-model="form.price"
            :invalid="invalid"
            :described-by="describedBy"
            @update:model-value="clear('price')"
          />
        </UiFormField>
      </div>

      <div class="flex items-start justify-between gap-4 rounded-lg border border-ink/[0.08] px-3.5 py-3">
        <div>
          <p class="text-sm font-medium text-ink">
            Produto ativo
          </p>
          <p class="text-xs text-ink/55">
            Produtos inativos continuam no histórico de vendas.
          </p>
          <p
            v-if="fieldErrors.status"
            class="mt-1 text-xs text-danger-strong"
            role="alert"
          >
            {{ fieldErrors.status }}
          </p>
        </div>
        <UiSwitch
          v-model="form.active"
          label="Produto ativo"
        />
      </div>
    </form>

    <template #footer>
      <UiButton
        variant="outline"
        :disabled="pending"
        @click="open = false"
      >
        Cancelar
      </UiButton>
      <UiButton
        type="submit"
        form="product-form"
        variant="primary"
        :loading="pending"
      >
        {{ isEdit ? 'Salvar alterações' : 'Criar produto' }}
      </UiButton>
    </template>
  </UiDialog>
</template>
