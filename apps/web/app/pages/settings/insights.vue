<script setup lang="ts">
import { PhCheck, PhX } from '@phosphor-icons/vue'

useHead({ title: 'Insights · PulseBoard' })

const { organization } = useOrganization()
const { available } = useInsightsAvailability()
const { isOwner } = usePermissions()
const { update, pending } = useInsightsOptIn()
const toast = useToast()

const switchId = useId()
const optedIn = computed(() => organization.value?.insights.enabled ?? false)
/** Disabling is always allowed (the API accepts it even with AI_ENABLED off); enabling needs AI_ENABLED. */
const canToggle = computed(() => isOwner.value && (available.value || optedIn.value))

const disableOpen = ref(false)

const SENT = [
  'Receita, pedidos, ticket médio e clientes ativos do período e do período anterior',
  'Série de receita, distribuição de status e contagens de clientes novos e recorrentes',
  'Nome, SKU e status dos 5 produtos com maior receita',
]

const NOT_SENT = [
  'Nomes e e-mails de clientes',
  'Nomes e e-mails de usuários e o nome da organização',
  'Transações individuais, identificadores internos e API keys',
]

async function save(enabled: boolean) {
  try {
    await update(enabled)
    disableOpen.value = false
    toast.success(
      enabled ? 'Insights ativados' : 'Insights desativados',
      enabled ? 'O card de insights já aparece no dashboard.' : 'Os resumos já gerados foram apagados.',
    )
  }
  catch (rawError) {
    toast.error('Não foi possível alterar os insights', errorMessage(rawError))
  }
}

function onToggle(value: boolean) {
  if (value) {
    save(true)
  }
  else {
    disableOpen.value = true
  }
}
</script>

<template>
  <div>
    <PageHeader
      title="Insights"
      description="Resumo do período gerado por IA no dashboard, a partir dos indicadores calculados pela API."
    />

    <div class="space-y-4">
      <UiAlert v-if="!isOwner">
        Você pode consultar esta configuração, mas somente owners ativam ou desativam os insights.
      </UiAlert>
      <UiAlert
        v-if="!available"
        tone="warning"
      >
        A geração por IA está desligada neste ambiente. {{ optedIn ? 'A organização continua com o opt-in registrado e pode desativá-lo.' : 'Não é possível ativar os insights agora.' }}
      </UiAlert>

      <UiCard title="Insights com IA">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <label
              :for="switchId"
              class="text-sm font-medium text-ink"
            >
              Ativar insights nesta organização
            </label>
            <p class="mt-0.5 text-xs text-ink/65">
              {{ optedIn ? 'Ativado: owners e members podem gerar o resumo do período.' : 'Desativado: o dashboard não mostra o card de insights.' }}
            </p>
          </div>
          <UiSwitch
            :id="switchId"
            :model-value="optedIn"
            :disabled="!canToggle || pending"
            @update:model-value="onToggle"
          />
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
          <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-ink/65">
              Enviado à OpenAI
            </h3>
            <ul class="mt-2 space-y-1.5 text-sm text-ink/70">
              <li
                v-for="item in SENT"
                :key="item"
                class="flex gap-2"
              >
                <PhCheck
                  :size="14"
                  class="mt-0.5 shrink-0 text-success-strong"
                  aria-hidden="true"
                />
                {{ item }}
              </li>
            </ul>
          </div>
          <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-ink/65">
              Nunca enviado
            </h3>
            <ul class="mt-2 space-y-1.5 text-sm text-ink/70">
              <li
                v-for="item in NOT_SENT"
                :key="item"
                class="flex gap-2"
              >
                <PhX
                  :size="14"
                  class="mt-0.5 shrink-0 text-danger-strong"
                  aria-hidden="true"
                />
                {{ item }}
              </li>
            </ul>
          </div>
        </div>

        <template #footer>
          <InsightsDisclosure />
        </template>
      </UiCard>
    </div>

    <UiConfirmDialog
      v-model:open="disableOpen"
      title="Desativar os insights?"
      confirm-label="Desativar insights"
      tone="danger"
      :loading="pending"
      @confirm="save(false)"
    >
      <p>
        O card de insights sai do dashboard e os resumos já gerados desta organização são apagados.
      </p>
      <p class="mt-2">
        Um owner pode ativar novamente a qualquer momento.
      </p>
    </UiConfirmDialog>
  </div>
</template>
