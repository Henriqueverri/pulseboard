<script setup lang="ts">
import type { SelectOption } from '~/components/ui/Select.vue'
import { API_KEY_EXPIRATION_DAYS } from '~/types/api-key'
import type { ApiKeyExpiration, CreatedApiKey } from '~/types/api-key'

const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ created: [apiKey: CreatedApiKey, requested: ApiKeyExpiration] }>()

type ExpirationChoice = `${typeof API_KEY_EXPIRATION_DAYS[number]}` | 'never'

const EXPIRATION_OPTIONS: SelectOption<ExpirationChoice>[] = [
  ...API_KEY_EXPIRATION_DAYS.map(days => ({ value: `${days}` as ExpirationChoice, label: `${days} dias` })),
  { value: 'never', label: 'Sem expiração' },
]

const mutations = useApiKeyMutations()
const { fieldErrors, formError, capture, clear, reset } = useFormErrors(['name', 'expires_in_days'] as const)

const form = reactive({ name: '', expiration: '90' as ExpirationChoice })
const pending = ref(false)

watch(open, (value) => {
  if (value) {
    reset()
    form.name = ''
    form.expiration = '90'
  }
})

async function onSubmit() {
  reset()
  const requested: ApiKeyExpiration = form.expiration === 'never'
    ? null
    : Number(form.expiration) as Exclude<ApiKeyExpiration, null>

  pending.value = true
  try {
    const apiKey = await mutations.create({ name: form.name.trim(), expires_in_days: requested })
    open.value = false
    emit('created', apiKey, requested)
  }
  catch (error) {
    capture(error, 'Não foi possível criar a chave. Tente novamente.')
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <UiDialog
    v-model:open="open"
    title="Nova API Key"
    description="A chave autentica um sistema externo na API de ingestão desta organização."
    :persistent="pending"
    size="sm"
  >
    <form
      id="api-key-form"
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
        hint="Identifique quem usa a chave, por exemplo &quot;ERP produção&quot;."
        :error="fieldErrors.name"
      >
        <UiInput
          :id="id"
          v-model="form.name"
          maxlength="100"
          autocomplete="off"
          :invalid="invalid"
          :described-by="describedBy"
          @update:model-value="clear('name')"
        />
      </UiFormField>

      <UiFormField
        v-slot="{ id }"
        label="Expiração"
        hint="Na organização de demonstração, toda chave expira em 24 horas."
        :error="fieldErrors.expires_in_days"
      >
        <UiSelect
          :id="id"
          v-model="form.expiration"
          :options="EXPIRATION_OPTIONS"
          @update:model-value="clear('expires_in_days')"
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
        form="api-key-form"
        variant="primary"
        :loading="pending"
      >
        Criar chave
      </UiButton>
    </template>
  </UiDialog>
</template>
