<script setup lang="ts">
import { PhKey } from '@phosphor-icons/vue'
import type { ApiKeyExpiration, CreatedApiKey } from '~/types/api-key'

/**
 * Shows the secret of a key that was just created. The parent drops `apiKey` when the
 * dialog closes, so the secret only lives in memory while it is on screen.
 */
const props = defineProps<{
  apiKey: CreatedApiKey | null
  requested: ApiKeyExpiration
}>()

const open = defineModel<boolean>('open', { default: false })

const { timezone } = useOrganization()
const input = useTemplateRef<HTMLInputElement>('secret')
const copyFailed = ref(false)

const expiresAt = computed(() => props.apiKey?.expires_at
  ? formatDateTime(props.apiKey.expires_at, timezone.value, { time: true })
  : null)
const limited = computed(() => props.apiKey ? expirationWasLimited(props.apiKey, props.requested) : false)

watch(open, (value) => {
  if (value) {
    copyFailed.value = false
  }
})

function selectSecret() {
  input.value?.select()
}

function onCopyFailed() {
  copyFailed.value = true
  selectSecret()
}
</script>

<template>
  <UiDialog
    v-model:open="open"
    title="Copie sua API Key agora"
    persistent
  >
    <div
      v-if="apiKey"
      class="space-y-4"
    >
      <UiAlert
        tone="warning"
        title="Esta é a única vez que a chave aparece."
      >
        O PulseBoard guarda apenas um hash: depois de fechar esta janela, ela não pode ser recuperada.
        Se perder a chave, revogue-a e crie outra.
      </UiAlert>

      <div>
        <label
          for="api-key-secret"
          class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-ink"
        >
          <PhKey
            :size="14"
            aria-hidden="true"
          />
          {{ apiKey.name }}
        </label>
        <div class="flex flex-col gap-2 sm:flex-row">
          <input
            id="api-key-secret"
            ref="secret"
            :value="apiKey.plain_text_key"
            readonly
            autocomplete="off"
            spellcheck="false"
            class="h-9 min-w-0 flex-1 rounded-lg border border-ink/10 bg-ink/[0.03] px-3 font-mono text-xs text-ink outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15"
            @focus="selectSecret"
          >
          <UiCopyButton
            :value="apiKey.plain_text_key"
            label="Copiar chave"
            text="Copiar chave"
            variant="primary"
            size="md"
            @failed="onCopyFailed"
          />
        </div>
        <p
          v-if="copyFailed"
          class="mt-1.5 text-xs text-danger-strong"
          role="alert"
        >
          Não foi possível copiar automaticamente. A chave está selecionada: copie com Ctrl+C (⌘C no Mac).
        </p>
      </div>

      <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-xs text-ink/65">
            Expira em
          </dt>
          <dd class="mt-0.5 font-medium tabular">
            {{ expiresAt ?? 'Não expira' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs text-ink/65">
            Como usar
          </dt>
          <dd class="mt-0.5 font-mono text-xs">
            Authorization: Bearer &lt;chave&gt;
          </dd>
        </div>
      </dl>

      <p
        v-if="limited"
        class="text-xs text-ink/65"
      >
        A validade foi limitada pela organização: chaves da organização de demonstração expiram em 24 horas.
      </p>
    </div>

    <template #footer>
      <UiButton
        variant="primary"
        @click="open = false"
      >
        Já guardei a chave
      </UiButton>
    </template>
  </UiDialog>
</template>
