<script setup lang="ts" generic="T extends string">
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui'

export interface SegmentOption<V extends string = string> {
  value: V
  label: string
}

withDefaults(defineProps<{
  options: SegmentOption<T>[]
  label: string
  size?: 'sm' | 'md'
}>(), {
  size: 'sm',
})

const model = defineModel<T>({ required: true })

function onUpdate(value: unknown) {
  // A single-choice group must always keep one option selected.
  if (typeof value === 'string' && value !== '') {
    model.value = value as T
  }
}
</script>

<template>
  <ToggleGroupRoot
    type="single"
    :model-value="model"
    :aria-label="label"
    class="inline-flex shrink-0 items-center gap-0.5 rounded-lg bg-ink/[0.05] p-0.5"
    @update:model-value="onUpdate"
  >
    <ToggleGroupItem
      v-for="option in options"
      :key="option.value"
      :value="option.value"
      class="inline-flex items-center justify-center whitespace-nowrap rounded-md px-2.5 font-medium text-ink/65 transition-colors hover:text-ink data-[state=on]:bg-surface data-[state=on]:text-ink data-[state=on]:shadow-soft"
      :class="size === 'sm' ? 'h-7 text-xs' : 'h-8 text-sm'"
    >
      {{ option.label }}
    </ToggleGroupItem>
  </ToggleGroupRoot>
</template>
