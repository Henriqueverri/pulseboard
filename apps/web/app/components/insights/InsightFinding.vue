<script setup lang="ts">
import { PhArrowRight, PhInfo, PhTrendDown, PhTrendUp, PhWarning } from '@phosphor-icons/vue'
import type { RouteLocationRaw } from 'vue-router'
import type { InsightFinding as Finding } from '~/types/insights'

/** Icon and tone come from `kind` and the link from `destination`: the UI never interprets the text. */
const props = defineProps<{
  finding: Finding
  currency: string
  /** Route of `finding.destination` with the current period; null hides the link. */
  to: RouteLocationRaw | null
  periodLabel: string
}>()

const presentation = computed(() => ({
  positive: { icon: PhTrendUp, tone: 'bg-success-soft text-success-strong' },
  negative: { icon: PhTrendDown, tone: 'bg-danger-soft text-danger-strong' },
  neutral: { icon: PhInfo, tone: 'bg-ink/[0.05] text-ink/65' },
  attention: { icon: PhWarning, tone: 'bg-warning-soft text-warning-strong' },
})[props.finding.kind] ?? { icon: PhInfo, tone: 'bg-ink/[0.05] text-ink/65' })

const kindLabel = computed(() => KIND_LABELS[props.finding.kind] ?? KIND_LABELS.neutral)
const destinationLabel = computed(() => DESTINATION_LABELS[props.finding.destination])
</script>

<template>
  <li class="flex gap-3">
    <span
      class="flex size-8 shrink-0 items-center justify-center rounded-lg"
      :class="presentation.tone"
    >
      <component
        :is="presentation.icon"
        :size="16"
        weight="bold"
        aria-hidden="true"
      />
      <span class="sr-only">{{ kindLabel }}:</span>
    </span>
    <div class="min-w-0 flex-1">
      <h3 class="text-sm font-semibold text-ink">
        {{ finding.title }}
      </h3>
      <p class="mt-0.5 text-sm text-ink/70">
        {{ finding.explanation }}
      </p>
      <dl
        v-if="finding.evidence.length"
        class="mt-2 grid gap-1.5 sm:grid-cols-2"
        :aria-label="`Evidências de “${finding.title}”`"
      >
        <InsightEvidence
          v-for="evidence in finding.evidence"
          :key="evidence.ref"
          :evidence="evidence"
          :currency="currency"
        />
      </dl>
      <NuxtLink
        v-if="to && destinationLabel"
        :to="to"
        class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-ink underline-offset-2 hover:underline"
        :aria-label="`Ver ${destinationLabel}, ${periodLabel}`"
      >
        Ver {{ destinationLabel }}
        <PhArrowRight
          :size="12"
          aria-hidden="true"
        />
      </NuxtLink>
    </div>
  </li>
</template>
