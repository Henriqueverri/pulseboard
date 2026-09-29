<script setup lang="ts">
import { PhArrowDownRight, PhArrowUpRight, PhMinus, PhSparkle } from '@phosphor-icons/vue'
import type { Comparison } from '~/types/api'
import type { Polarity } from '~/utils/comparison'

const props = withDefaults(defineProps<{
  metric: Comparison<number | string | null>
  polarity?: Polarity
  size?: 'sm' | 'md'
}>(), {
  polarity: 'positive',
  size: 'md',
})

const description = computed(() => describeChange(props.metric, props.polarity))

const icon = computed(() => ({
  up: PhArrowUpRight,
  down: PhArrowDownRight,
  flat: PhMinus,
  new: PhSparkle,
  none: null,
})[description.value.trend])
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center gap-0.5 whitespace-nowrap rounded-md font-medium tabular"
    :class="[
      size === 'sm' ? 'h-5 px-1 text-[11px]' : 'h-6 px-1.5 text-xs',
      description.trend === 'none'
        ? 'text-ink/65'
        : description.tone === 'positive'
          ? 'bg-success-soft text-success-strong'
          : description.tone === 'negative'
            ? 'bg-danger-soft text-danger-strong'
            : 'bg-ink/[0.05] text-ink/65',
    ]"
    :title="description.label"
  >
    <component
      :is="icon"
      v-if="icon"
      :size="size === 'sm' ? 11 : 12"
      weight="bold"
      aria-hidden="true"
    />
    <span aria-hidden="true">{{ description.text }}</span>
    <span class="sr-only">{{ description.label }}</span>
  </span>
</template>
