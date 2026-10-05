<script setup lang="ts">
import { PhDatabase, PhPlugsConnected } from '@phosphor-icons/vue'
import { TRANSACTION_SOURCE_DESCRIPTIONS, TRANSACTION_SOURCE_LABELS } from '~/types/transaction'
import type { TransactionSource } from '~/types/transaction'

withDefaults(defineProps<{
  source: TransactionSource
  size?: 'sm' | 'md'
}>(), {
  size: 'sm',
})
</script>

<template>
  <UiTag
    :tone="source === 'ingest' ? 'brand' : 'neutral'"
    :size="size"
    :title="TRANSACTION_SOURCE_DESCRIPTIONS[source]"
  >
    <component
      :is="source === 'ingest' ? PhPlugsConnected : PhDatabase"
      :size="size === 'sm' ? 11 : 13"
      aria-hidden="true"
    />
    {{ TRANSACTION_SOURCE_LABELS[source] }}
  </UiTag>
</template>
