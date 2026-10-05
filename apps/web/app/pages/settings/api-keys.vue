<script setup lang="ts">
import { PhKey, PhPlus } from '@phosphor-icons/vue'
import type { DataColumn } from '~/components/data/DataTable.vue'
import type { ApiKey, ApiKeyExpiration, CreatedApiKey } from '~/types/api-key'

useHead({ title: 'API Keys · PulseBoard' })

const { data: apiKeys, status, error, refresh } = useApiKeys()
const mutations = useApiKeyMutations()
const { isOwner } = usePermissions()
const { timezone } = useOrganization()
const toast = useToast()

const rows = computed(() => apiKeys.value ?? [])
const initialLoading = computed(() => status.value === 'pending' && !apiKeys.value)
const refreshing = computed(() => status.value === 'pending' && !!apiKeys.value)

const columns = computed<DataColumn[]>(() => [
  { key: 'name', label: 'Chave' },
  { key: 'status', label: 'Status', hideBelow: 'sm' },
  { key: 'created_at', label: 'Criada', hideBelow: 'md' },
  { key: 'last_used_at', label: 'Último uso', hideBelow: 'lg' },
  { key: 'expires_at', label: 'Expiração', hideBelow: 'lg' },
  ...(isOwner.value ? [{ key: 'actions', label: 'Ações', srOnlyLabel: true, align: 'right' as const, class: 'w-24' }] : []),
])

function dateTime(value: string | null): string | null {
  return value ? formatDateTime(value, timezone.value, { time: true }) : null
}

function expirationLabel(apiKey: ApiKey): string {
  if (apiKey.status === 'revoked') {
    return `Revogada em ${dateTime(apiKey.revoked_at)}`
  }
  if (!apiKey.expires_at) {
    return 'Não expira'
  }

  return apiKey.status === 'expired' ? `Expirou em ${dateTime(apiKey.expires_at)}` : `Expira em ${dateTime(apiKey.expires_at)}`
}

const createOpen = ref(false)
const secretOpen = ref(false)
/** Held only while the secret dialog is open. */
const revealed = shallowRef<CreatedApiKey | null>(null)
const revealedRequested = ref<ApiKeyExpiration>(null)

function onCreated(apiKey: CreatedApiKey, requested: ApiKeyExpiration) {
  revealed.value = apiKey
  revealedRequested.value = requested
  secretOpen.value = true
  refresh()
}

watch(secretOpen, (open) => {
  if (!open) {
    revealed.value = null
  }
})

const revokeOpen = ref(false)
const revoking = shallowRef<ApiKey | null>(null)
const revokePending = ref(false)

function askRevoke(apiKey: ApiKey) {
  revoking.value = apiKey
  revokeOpen.value = true
}

async function confirmRevoke() {
  const apiKey = revoking.value
  if (!apiKey) {
    return
  }

  revokePending.value = true
  try {
    await mutations.revoke(apiKey.id)
    revokeOpen.value = false
    toast.success('Chave revogada', `Requisições com ${maskedApiKey(apiKey.prefix)} passam a receber 401.`)
    await refresh()
  }
  catch (rawError) {
    toast.error('Não foi possível revogar a chave', errorMessage(rawError))
  }
  finally {
    revokePending.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader
      title="API Keys"
      description="Chaves que autenticam sistemas externos na API de ingestão. Cada chave envia dados somente para esta organização."
    >
      <template
        v-if="isOwner"
        #actions
      >
        <UiButton
          variant="primary"
          @click="createOpen = true"
        >
          <template #leading>
            <PhPlus :size="16" />
          </template>
          Nova chave
        </UiButton>
      </template>
    </PageHeader>

    <UiAlert
      v-if="!isOwner"
      class="mb-4"
    >
      Você pode consultar as chaves da organização, mas somente owners criam e revogam chaves.
    </UiAlert>

    <div class="space-y-6">
      <UiCard padding="none">
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
          :request-id="asApiError(error)?.requestId"
          :retrying="refreshing"
          @retry="refresh()"
        />
        <DataTable
          v-else
          :columns="columns"
          :rows="rows"
          :row-key="row => row.id"
          caption="API Keys da organização"
          :loading="initialLoading"
          :busy="refreshing"
          :skeleton-rows="3"
        >
          <template #cell-name="{ row }">
            <p
              class="truncate font-medium"
              :class="row.status === 'active' ? 'text-ink' : 'text-ink/65'"
            >
              {{ row.name }}
            </p>
            <p class="font-mono text-xs text-ink/65">
              {{ maskedApiKey(row.prefix) }}
            </p>
            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink/65 lg:hidden">
              <ApiKeyStatusBadge
                :status="row.status"
                class="sm:hidden"
              />
              <span>{{ expirationLabel(row) }}</span>
              <span>· {{ row.last_used_at ? `Usada em ${dateTime(row.last_used_at)}` : 'Nunca usada' }}</span>
            </div>
          </template>
          <template #cell-status="{ row }">
            <ApiKeyStatusBadge :status="row.status" />
          </template>
          <template #cell-created_at="{ row }">
            <p class="whitespace-nowrap text-ink/70 tabular">
              {{ dateTime(row.created_at) }}
            </p>
            <p class="truncate text-xs text-ink/65">
              {{ row.created_by ? `por ${row.created_by.name}` : 'Usuário removido' }}
            </p>
          </template>
          <template #cell-last_used_at="{ row }">
            <span
              class="whitespace-nowrap tabular"
              :class="row.last_used_at ? 'text-ink/70' : 'text-ink/65'"
            >{{ dateTime(row.last_used_at) ?? 'Nunca usada' }}</span>
          </template>
          <template #cell-expires_at="{ row }">
            <span class="text-ink/70 tabular">{{ expirationLabel(row) }}</span>
          </template>
          <template #cell-actions="{ row }">
            <UiButton
              v-if="row.status === 'active'"
              variant="ghost"
              size="sm"
              class="text-danger-strong"
              :aria-label="`Revogar a chave ${row.name}`"
              @click="askRevoke(row)"
            >
              Revogar
            </UiButton>
          </template>
          <template #empty>
            <UiEmptyState
              :icon="PhKey"
              title="Nenhuma API Key criada"
              :description="isOwner
                ? 'Crie uma chave para que um sistema externo envie transações para esta organização.'
                : 'Quando um owner criar uma chave, ela aparece aqui.'"
            >
              <UiButton
                v-if="isOwner"
                variant="outline"
                size="sm"
                @click="createOpen = true"
              >
                Criar a primeira chave
              </UiButton>
            </UiEmptyState>
          </template>
        </DataTable>
      </UiCard>

      <IngestionGuide />
    </div>

    <ApiKeyCreateDialog
      v-model:open="createOpen"
      @created="onCreated"
    />

    <ApiKeySecretDialog
      v-model:open="secretOpen"
      :api-key="revealed"
      :requested="revealedRequested"
    />

    <UiConfirmDialog
      v-model:open="revokeOpen"
      :title="revoking ? `Revogar a chave “${revoking.name}”?` : 'Revogar a chave?'"
      confirm-label="Revogar chave"
      tone="danger"
      :loading="revokePending"
      @confirm="confirmRevoke"
    >
      <p>
        Integrações que usam <span class="font-mono text-ink">{{ revoking ? maskedApiKey(revoking.prefix) : '' }}</span>
        passam a receber 401 imediatamente.
      </p>
      <p class="mt-2">
        A chave continua listada para auditoria e não pode ser reativada.
      </p>
    </UiConfirmDialog>
  </div>
</template>
