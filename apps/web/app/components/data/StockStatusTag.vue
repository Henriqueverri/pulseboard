<script setup lang="ts">
import type { StockStatus } from '~/types/analytics'

const props = withDefaults(defineProps<{
  status: StockStatus
  size?: 'sm' | 'md'
}>(), {
  size: 'md',
})

const config = computed(() => STOCK_STATUS[props.status])
</script>

<template>
  <UiTag
    :tone="config.tone"
    :size="size"
    dot
  >
    {{ config.label }}
  </UiTag>
</template>
