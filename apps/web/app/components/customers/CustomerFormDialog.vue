<script setup lang="ts">
import type { Customer, CustomerInput } from '~/types/customer'

const props = withDefaults(defineProps<{
  /** `null` creates a customer. */
  customer?: Customer | null
}>(), {
  customer: null,
})

const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ saved: [customer: Customer, mode: 'created' | 'updated'] }>()

const mutations = useCustomerMutations()
const { fieldErrors, formError, capture, clear, reset } = useFormErrors(['name', 'email'] as const)

const form = reactive({ name: '', email: '' })
const pending = ref(false)
const isEdit = computed(() => props.customer !== null)

watch(open, (value) => {
  if (!value) {
    return
  }

  reset()
  form.name = props.customer?.name ?? ''
  form.email = props.customer?.email ?? ''
}, { immediate: true })

async function onSubmit() {
  reset()
  const input: CustomerInput = { name: form.name.trim(), email: form.email.trim() }

  pending.value = true
  try {
    if (props.customer) {
      if (Object.keys(changedCustomerFields(props.customer, input)).length === 0) {
        open.value = false

        return
      }
      emit('saved', await mutations.update(props.customer, input), 'updated')
    }
    else {
      emit('saved', await mutations.create(input), 'created')
    }
    open.value = false
  }
  catch (error) {
    capture(error, 'Não foi possível salvar o cliente. Tente novamente.')
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <UiDialog
    v-model:open="open"
    :title="isEdit ? 'Editar cliente' : 'Novo cliente'"
    :persistent="pending"
    size="sm"
  >
    <form
      id="customer-form"
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

      <UiFormField
        v-slot="{ id, describedBy, invalid }"
        label="E-mail"
        required
        hint="Único na organização, inclusive entre clientes removidos."
        :error="fieldErrors.email"
      >
        <UiInput
          :id="id"
          v-model="form.email"
          type="email"
          inputmode="email"
          maxlength="255"
          autocomplete="off"
          spellcheck="false"
          :invalid="invalid"
          :described-by="describedBy"
          @update:model-value="clear('email')"
        />
      </UiFormField>
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
        form="customer-form"
        variant="primary"
        :loading="pending"
      >
        {{ isEdit ? 'Salvar alterações' : 'Criar cliente' }}
      </UiButton>
    </template>
  </UiDialog>
</template>
