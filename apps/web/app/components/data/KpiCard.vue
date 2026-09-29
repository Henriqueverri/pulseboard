<script setup lang="ts" generic="V extends number | string | null">
import type { Comparison } from '~/types/api'
import type { Polarity } from '~/utils/comparison'

const props = withDefaults(defineProps<{
  label: string
  metric: Comparison<V>
  format: (value: V) => string
  polarity?: Polarity
}>(), {
  polarity: 'positive',
})

const previous = computed(() => props.format(props.metric.previous))
</script>

<template>
  <div class="flex min-w-0 flex-col rounded-2xl border border-ink/[0.06] bg-surface p-4 sm:p-5">
    <p class="text-sm text-ink/55">
      {{ label }}
    </p>
    <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1">
      <p class="text-lg font-semibold tracking-tight text-ink tabular sm:text-2xl">
        {{ format(metric.value) }}
      </p>
      <ChangeIndicator
        :metric="metric"
        :polarity="polarity"
      />
    </div>
    <p class="mt-1 text-xs text-ink/50 tabular">
      vs {{ previous }} no período anterior
    </p>
  </div>
</template>
