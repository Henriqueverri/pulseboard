<script setup lang="ts">
import { PhCheckCircle, PhInfo, PhWarning, PhWarningCircle } from '@phosphor-icons/vue'

const props = withDefaults(defineProps<{
  tone?: 'info' | 'success' | 'warning' | 'danger'
  title?: string
}>(), {
  tone: 'info',
  title: undefined,
})

const icon = computed(() => ({
  info: PhInfo,
  success: PhCheckCircle,
  warning: PhWarning,
  danger: PhWarningCircle,
})[props.tone])
</script>

<template>
  <div
    class="flex gap-3 rounded-lg px-3.5 py-3 text-sm"
    :class="{
      'bg-info-soft text-info-strong': tone === 'info',
      'bg-success-soft text-success-strong': tone === 'success',
      'bg-warning-soft text-warning-strong': tone === 'warning',
      'bg-danger-soft text-danger-strong': tone === 'danger',
    }"
    :role="tone === 'danger' ? 'alert' : 'status'"
  >
    <component
      :is="icon"
      :size="18"
      weight="fill"
      class="mt-px shrink-0"
      aria-hidden="true"
    />
    <div class="min-w-0">
      <p
        v-if="title"
        class="font-medium"
      >
        {{ title }}
      </p>
      <div :class="title ? 'mt-0.5 opacity-90' : ''">
        <slot />
      </div>
    </div>
  </div>
</template>
