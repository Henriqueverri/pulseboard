<script setup lang="ts">
import { PhBell, PhDeviceMobile } from '@phosphor-icons/vue'

useHead({ title: 'Notificações · PulseBoard' })

const { organization, currency } = useOrganization()
const { isOwner } = usePermissions()
const { updateGoal, pending } = useNotificationSettings()
const toast = useToast()
const { fieldErrors, formError, capture, clear, reset, setFieldError } = useFormErrors(['daily_revenue_goal'] as const)

const currentGoal = computed(() => organization.value?.daily_revenue_goal ?? null)
const goal = ref<string | null>(currentGoal.value)

watch(currentGoal, (value) => {
  goal.value = value
})

const ALERTS = [
  {
    title: 'Estoque baixo',
    description: 'Um produto com controle de estoque chega ao mínimo, por exemplo "Heineken Long Neck (3 un., mínimo 5)".',
  },
  {
    title: 'Estoque zerado',
    description: 'Um produto com controle de estoque acaba.',
  },
  {
    title: 'Meta do dia batida',
    description: 'As vendas pagas de hoje, no fuso da organização, alcançam a meta diária abaixo.',
  },
]

async function save() {
  reset()

  if (goal.value === null) {
    setFieldError('daily_revenue_goal', 'Informe um valor válido, por exemplo 1.500,00.')

    return
  }

  try {
    await updateGoal(goal.value)
    toast.success('Meta diária salva', `O aviso chega quando as vendas pagas do dia somarem ${formatMoney(goal.value, currency.value)}.`)
  }
  catch (error) {
    capture(error, 'Não foi possível salvar a meta.')
  }
}

async function remove() {
  reset()

  try {
    await updateGoal(null)
    toast.success('Meta diária removida', 'O aviso de meta batida deixa de ser enviado.')
  }
  catch (error) {
    capture(error, 'Não foi possível remover a meta.')
  }
}
</script>

<template>
  <div>
    <PageHeader
      title="Notificações"
      description="Avisos enviados ao app PulseBoard no celular de owners e members desta organização."
    />

    <div class="space-y-4">
      <UiAlert v-if="!isOwner">
        Você pode consultar esta configuração, mas somente owners alteram a meta diária.
      </UiAlert>

      <UiCard title="Avisos enviados">
        <ul class="space-y-3">
          <li
            v-for="alert in ALERTS"
            :key="alert.title"
            class="flex gap-3"
          >
            <PhBell
              :size="16"
              class="mt-0.5 shrink-0 text-brand-700"
              aria-hidden="true"
            />
            <div class="min-w-0">
              <p class="text-sm font-medium text-ink">
                {{ alert.title }}
              </p>
              <p class="text-xs text-ink/65">
                {{ alert.description }}
              </p>
            </div>
          </li>
        </ul>
        <p class="mt-4 text-xs text-ink/65">
          Cada aviso sai no máximo uma vez por dia. Um produto que volta a ficar baixo no mesmo dia não gera outro aviso.
        </p>

        <template #footer>
          <p class="flex items-start gap-2 text-xs text-ink/65">
            <PhDeviceMobile
              :size="14"
              class="mt-0.5 shrink-0"
              aria-hidden="true"
            />
            Para receber, entre no app PulseBoard e permita as notificações. Cada pessoa liga ou desliga os avisos no próprio celular, na aba Conta.
          </p>
        </template>
      </UiCard>

      <UiCard
        title="Meta diária de receita"
        description="Sem meta, o aviso de meta batida não é enviado."
      >
        <form
          class="flex flex-col gap-4"
          novalidate
          @submit.prevent="save"
        >
          <UiAlert
            v-if="formError"
            tone="danger"
          >
            {{ formError }}
          </UiAlert>

          <div class="max-w-xs">
            <UiFormField
              v-slot="{ id, describedBy, invalid }"
              label="Meta de vendas pagas por dia"
              :hint="currentGoal ? `Meta atual: ${formatMoney(currentGoal, currency)}.` : 'Nenhuma meta definida.'"
              :error="fieldErrors.daily_revenue_goal"
            >
              <UiMoneyInput
                :id="id"
                v-model="goal"
                :disabled="!isOwner || pending"
                :invalid="invalid"
                :described-by="describedBy"
                @update:model-value="clear('daily_revenue_goal')"
              />
            </UiFormField>
          </div>

          <div
            v-if="isOwner"
            class="flex flex-wrap gap-2"
          >
            <UiButton
              type="submit"
              variant="primary"
              :loading="pending"
            >
              Salvar meta
            </UiButton>
            <UiButton
              v-if="currentGoal"
              variant="ghost"
              :disabled="pending"
              @click="remove"
            >
              Remover meta
            </UiButton>
          </div>
        </form>
      </UiCard>
    </div>
  </div>
</template>
