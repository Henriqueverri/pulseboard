<script setup lang="ts">
import type { TagTone } from '~/components/ui/Tag.vue'

export type KnownStatus = 'active' | 'inactive' | 'paid' | 'pending' | 'refunded' | 'canceled'

const props = withDefaults(defineProps<{
  status: KnownStatus
  size?: 'sm' | 'md'
}>(), {
  size: 'md',
})

const STATUS: Record<KnownStatus, { label: string, tone: TagTone }> = {
  active: { label: 'Ativo', tone: 'success' },
  inactive: { label: 'Inativo', tone: 'neutral' },
  paid: { label: 'Pago', tone: 'success' },
  pending: { label: 'Pendente', tone: 'warning' },
  refunded: { label: 'Reembolsado', tone: 'info' },
  canceled: { label: 'Cancelado', tone: 'danger' },
}

const config = computed(() => STATUS[props.status])
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
