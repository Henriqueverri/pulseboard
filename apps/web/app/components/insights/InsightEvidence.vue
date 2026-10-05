<script setup lang="ts">
import type { InsightEvidence as Evidence } from '~/types/insights'

/** A cited metric: label, value and comparison all come from the API, never from the model's text. */
const props = defineProps<{
  evidence: Evidence
  currency: string
}>()

const metric = computed(() => ({
  value: props.evidence.value,
  previous: props.evidence.previous,
  change: props.evidence.change,
}))
</script>

<template>
  <div class="flex min-w-0 items-center justify-between gap-3 rounded-lg bg-ink/[0.03] px-3 py-2">
    <dt class="min-w-0 truncate text-xs text-ink/65">
      {{ evidence.label }}
    </dt>
    <dd class="flex shrink-0 items-center gap-2">
      <span class="text-sm font-semibold text-ink tabular">{{ formatEvidenceValue(evidence, currency) }}</span>
      <ChangeIndicator
        :metric="metric"
        :polarity="evidence.polarity"
        size="sm"
      />
    </dd>
  </div>
</template>
