<script setup lang="ts">
import type { TagTone } from '~/components/ui/Tag.vue'
import type { ApiKeyStatus } from '~/types/api-key'

defineProps<{ status: ApiKeyStatus }>()

const STATUS: Record<ApiKeyStatus, { label: string, tone: TagTone }> = {
  active: { label: 'Ativa', tone: 'success' },
  revoked: { label: 'Revogada', tone: 'danger' },
  expired: { label: 'Expirada', tone: 'neutral' },
}
</script>

<template>
  <UiTag
    :tone="STATUS[status].tone"
    size="sm"
    dot
  >
    {{ STATUS[status].label }}
  </UiTag>
</template>
