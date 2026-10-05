<script setup lang="ts">
import { PhShieldCheck } from '@phosphor-icons/vue'
import type { InsightMeta } from '~/types/insights'

/** What is sent to the AI provider and, with `meta`, which model generated the summary and when. */
withDefaults(defineProps<{
  meta?: InsightMeta | null
  timezone?: string
}>(), {
  meta: null,
  timezone: 'UTC',
})
</script>

<template>
  <div class="flex gap-2 text-xs text-ink/65">
    <PhShieldCheck
      :size="14"
      class="mt-px shrink-0"
      aria-hidden="true"
    />
    <div class="min-w-0 space-y-0.5">
      <p v-if="meta">
        Gerado por IA ({{ meta.model }}) em {{ formatDateTime(meta.generated_at, timezone) }}.
        Os números vêm da API do PulseBoard; o texto é uma interpretação e pode conter imprecisões.
      </p>
      <p>
        Para gerar a análise, dados agregados do período (sem nomes ou e-mails de clientes) são enviados à OpenAI.
      </p>
    </div>
  </div>
</template>
