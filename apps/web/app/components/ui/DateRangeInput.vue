<script setup lang="ts">
/**
 * Pair of native date inputs bound to civil dates (`YYYY-MM-DD`), the exact format of the API.
 * Native inputs give keyboard, screen-reader and mobile pickers for free.
 */
withDefaults(defineProps<{
  fromLabel?: string
  toLabel?: string
  max?: string
  invalid?: boolean
  size?: 'sm' | 'md'
}>(), {
  fromLabel: 'Data inicial',
  toLabel: 'Data final',
  max: undefined,
  invalid: false,
  size: 'md',
})

const from = defineModel<string>('from', { default: '' })
const to = defineModel<string>('to', { default: '' })
</script>

<template>
  <div class="flex items-center gap-2">
    <UiInput
      v-model="from"
      type="date"
      :size="size"
      :max="to || max"
      :aria-label="fromLabel"
      :invalid="invalid"
      class="min-w-0 tabular"
    />
    <span
      class="shrink-0 text-xs text-ink/65"
      aria-hidden="true"
    >até</span>
    <UiInput
      v-model="to"
      type="date"
      :size="size"
      :min="from || undefined"
      :max="max"
      :aria-label="toLabel"
      :invalid="invalid"
      class="min-w-0 tabular"
    />
  </div>
</template>
