<script setup lang="ts" generic="T extends string">
import { PhCaretDown, PhCheck } from '@phosphor-icons/vue'
import {
  SelectContent,
  SelectItem,
  SelectItemIndicator,
  SelectItemText,
  SelectPortal,
  SelectRoot,
  SelectTrigger,
  SelectValue,
  SelectViewport,
} from 'reka-ui'

export interface SelectOption<V extends string = string> {
  value: V
  label: string
}

withDefaults(defineProps<{
  options: SelectOption<T>[]
  placeholder?: string
  label?: string
  id?: string
  size?: 'sm' | 'md'
  invalid?: boolean
  disabled?: boolean
}>(), {
  placeholder: 'Selecione',
  label: undefined,
  id: undefined,
  size: 'md',
  invalid: false,
  disabled: false,
})

const model = defineModel<T>()
</script>

<template>
  <SelectRoot
    v-model="model"
    :disabled="disabled"
  >
    <SelectTrigger
      :id="id"
      :aria-label="label"
      :aria-invalid="invalid || undefined"
      class="inline-flex w-full items-center justify-between gap-2 rounded-lg border bg-surface px-3 text-left text-sm text-ink transition-colors hover:border-ink/20 data-[placeholder]:text-ink/40 disabled:opacity-50"
      :class="[
        size === 'sm' ? 'h-8' : 'h-9',
        invalid ? 'border-danger-strong/60' : 'border-ink/10',
      ]"
    >
      <SelectValue
        :placeholder="placeholder"
        class="truncate"
      />
      <PhCaretDown
        :size="14"
        class="shrink-0 text-ink/65"
        aria-hidden="true"
      />
    </SelectTrigger>
    <SelectPortal>
      <SelectContent
        position="popper"
        :side-offset="6"
        class="z-50 max-h-[var(--reka-select-content-available-height)] min-w-[var(--reka-select-trigger-width)] overflow-hidden rounded-lg bg-surface p-1 shadow-pop"
      >
        <SelectViewport>
          <SelectItem
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            class="relative flex h-8 cursor-pointer select-none items-center rounded-md pl-2 pr-8 text-sm text-ink outline-none data-[disabled]:pointer-events-none data-[highlighted]:bg-ink/[0.05] data-[state=checked]:font-medium"
          >
            <SelectItemText>{{ option.label }}</SelectItemText>
            <SelectItemIndicator class="absolute right-2 inline-flex items-center text-brand-600">
              <PhCheck :size="14" />
            </SelectItemIndicator>
          </SelectItem>
        </SelectViewport>
      </SelectContent>
    </SelectPortal>
  </SelectRoot>
</template>
