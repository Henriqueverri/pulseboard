<script setup lang="ts">
import { PhLockSimple, PhSparkle } from '@phosphor-icons/vue'
import type { ReportingPeriod } from '~/composables/useReportingPeriod'
import { AI_ERROR_CODES } from '~/types/insights'

/**
 * "Insights do período" on the dashboard. It loads independently of the other cards, so any
 * insights failure stays inside it. Numbers shown here come from the evidence the API resolved.
 */
const props = defineProps<{ period: ReportingPeriod }>()

const { enabled } = useInsightsAvailability()
const { summary, generate, generating, generateError } = usePeriodSummary(props.period)
const { isOwner } = usePermissions()
const { currency, timezone } = useOrganization()

const heading = useTemplateRef<HTMLElement>('heading')
const titleId = useId()

const response = computed(() => summary.data.value)
const result = computed(() => (response.value && response.value.data ? response.value : null))
const loading = computed(() => summary.status.value === 'pending' && !response.value)

const error = computed(() => generateError.value ?? summary.error.value ?? null)
const errorCode = computed(() => asApiError(error.value)?.code ?? null)

type View = 'not-enabled' | 'unavailable' | 'loading' | 'quota' | 'error' | 'result' | 'idle'

const view = computed<View>(() => {
  if (!enabled.value || errorCode.value === AI_ERROR_CODES.notEnabled) {
    return 'not-enabled'
  }
  if (errorCode.value === AI_ERROR_CODES.disabled) {
    return 'unavailable'
  }
  if (loading.value) {
    return 'loading'
  }
  if (errorCode.value === AI_ERROR_CODES.quotaExceeded) {
    return 'quota'
  }
  if (error.value) {
    return 'error'
  }

  return result.value ? 'result' : 'idle'
})

const quotaMessage = computed(() => {
  const retryAfter = asApiError(error.value)?.retryAfter

  return retryAfter
    ? `Novas gerações ficam disponíveis a partir das ${quotaResetTime(retryAfter, timezone.value)}. Resumos já gerados continuam aparecendo.`
    : 'Novas gerações ficam disponíveis amanhã. Resumos já gerados continuam aparecendo.'
})

const label = computed(() => periodLabel(props.period.preset.value, props.period.range.value))
const route = (destination: string) => destinationRoute(destination, {
  query: props.period.query.value,
  range: props.period.range.value,
})
const caveats = computed(() => caveatLabels(result.value?.data.caveats ?? []))

/** The GET failed: reload it. Otherwise the generation failed: generate again. */
function retry() {
  if (generateError.value) {
    return onGenerate()
  }

  return summary.refresh()
}

async function onGenerate() {
  await generate()

  if (result.value) {
    await nextTick()
    heading.value?.focus()
  }
}
</script>

<template>
  <UiCard :aria-labelledby="titleId">
    <template #header>
      <div class="min-w-0">
        <h2
          :id="titleId"
          ref="heading"
          tabindex="-1"
          class="flex items-center gap-1.5 text-sm font-semibold text-ink focus:outline-none"
        >
          <PhSparkle
            :size="16"
            weight="fill"
            class="text-brand-600"
            aria-hidden="true"
          />
          Insights do período
        </h2>
        <p class="mt-0.5 text-xs text-ink/65">
          Leitura dos indicadores feita por IA, com os números calculados pela API
        </p>
      </div>
    </template>
    <template
      v-if="view === 'result'"
      #actions
    >
      <UiTag
        tone="brand"
        size="sm"
      >
        Gerado por IA
      </UiTag>
    </template>

    <div
      aria-live="polite"
      :aria-busy="loading || generating || undefined"
    >
      <UiEmptyState
        v-if="view === 'not-enabled'"
        compact
        :icon="PhLockSimple"
        title="Insights não ativados"
        :description="isOwner
          ? 'Ative os insights para gerar uma leitura do período com IA. O restante do dashboard funciona normalmente.'
          : 'Um owner da organização pode ativar os insights em Configurações.'"
      >
        <UiButton
          v-if="isOwner"
          to="/settings/insights"
          variant="outline"
          size="sm"
        >
          Configurar insights
        </UiButton>
      </UiEmptyState>

      <UiEmptyState
        v-else-if="view === 'unavailable'"
        compact
        :icon="PhSparkle"
        title="Insights indisponíveis no momento"
        description="A geração por IA está desligada. O restante do dashboard continua funcionando."
      />

      <div
        v-else-if="view === 'loading'"
        class="space-y-3"
      >
        <span class="sr-only">Carregando insights do período</span>
        <UiSkeleton class="h-5 w-2/3" />
        <UiSkeleton class="h-4 w-full" />
        <UiSkeleton class="h-16 w-full rounded-lg" />
      </div>

      <UiErrorState
        v-else-if="view === 'quota'"
        compact
        title="Cota diária de insights atingida"
        :message="quotaMessage"
        :retryable="false"
      />

      <UiErrorState
        v-else-if="view === 'error'"
        compact
        title="Não foi possível obter os insights"
        :message="errorMessage(error)"
        :request-id="asApiError(error)?.requestId"
        :retrying="generating || summary.status.value === 'pending'"
        @retry="retry"
      />

      <div
        v-else-if="view === 'result' && result"
        class="space-y-5"
      >
        <div>
          <p class="text-base font-semibold text-ink">
            {{ result.data.headline }}
          </p>
          <p class="mt-1 text-sm text-ink/70">
            {{ result.data.overview }}
          </p>
        </div>

        <ul
          class="space-y-4"
          aria-label="Achados do período"
        >
          <InsightFinding
            v-for="(finding, index) in result.data.findings"
            :key="index"
            :finding="finding"
            :currency="currency"
            :to="route(finding.destination)"
            :period-label="label"
          />
        </ul>

        <section
          v-if="result.data.attention_points.length"
          aria-label="Pontos de atenção"
        >
          <h3 class="text-xs font-semibold uppercase tracking-wide text-ink/65">
            Pontos de atenção
          </h3>
          <ul class="mt-2 space-y-2">
            <li
              v-for="(point, index) in result.data.attention_points"
              :key="index"
            >
              <p class="text-sm text-ink/70">
                {{ point.text }}
              </p>
              <dl
                v-if="point.evidence.length"
                class="mt-1.5 grid gap-1.5 sm:grid-cols-2"
              >
                <InsightEvidence
                  v-for="evidence in point.evidence"
                  :key="evidence.ref"
                  :evidence="evidence"
                  :currency="currency"
                />
              </dl>
            </li>
          </ul>
        </section>

        <UiAlert
          v-if="caveats.length"
          tone="warning"
        >
          <p class="font-medium">
            Ressalvas sobre estes números
          </p>
          <ul class="mt-0.5 list-disc space-y-0.5 pl-4">
            <li
              v-for="caveat in caveats"
              :key="caveat"
            >
              {{ caveat }}
            </li>
          </ul>
        </UiAlert>

        <InsightsDisclosure
          :meta="result.meta.insight"
          :timezone="timezone"
        />
      </div>

      <div
        v-else
        class="flex flex-col items-start gap-3"
      >
        <p class="text-sm text-ink/70">
          Gere uma leitura dos indicadores de {{ label }}: o que mudou, onde investigar e as ressalvas dos números.
        </p>
        <UiButton
          variant="primary"
          :loading="generating"
          @click="onGenerate"
        >
          <template #leading>
            <PhSparkle :size="16" />
          </template>
          {{ generating ? 'Analisando o período…' : 'Gerar insights do período' }}
        </UiButton>
        <InsightsDisclosure />
      </div>
    </div>
  </UiCard>
</template>
