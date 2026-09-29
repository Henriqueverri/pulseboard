<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

export type ButtonVariant = 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger'
export type ButtonSize = 'sm' | 'md' | 'lg'

const props = withDefaults(defineProps<{
  variant?: ButtonVariant
  size?: ButtonSize
  type?: 'button' | 'submit' | 'reset'
  to?: RouteLocationRaw
  loading?: boolean
  disabled?: boolean
  /** Square button for a single icon; requires an accessible `aria-label`. */
  icon?: boolean
  block?: boolean
}>(), {
  variant: 'secondary',
  size: 'md',
  type: 'button',
  to: undefined,
  loading: false,
  disabled: false,
  icon: false,
  block: false,
})

const NuxtLink = resolveComponent('NuxtLink')

// SnowUI Button: Filled / Gray / Outline / Borderless variants; 36px medium height, 16px padding, 6px gap.
const variants: Record<ButtonVariant, string> = {
  primary: 'bg-ink text-white hover:bg-ink/85 active:bg-ink/75',
  secondary: 'bg-ink/[0.05] text-ink hover:bg-ink/[0.09] active:bg-ink/[0.12]',
  outline: 'border border-ink/10 bg-surface text-ink hover:bg-ink/[0.04] active:bg-ink/[0.07]',
  ghost: 'text-ink hover:bg-ink/[0.05] active:bg-ink/[0.08]',
  danger: 'bg-danger-strong text-white hover:bg-danger-strong/90 active:bg-danger-strong/80',
}

const sizes: Record<ButtonSize, string> = {
  sm: 'h-8 gap-1.5 rounded-md px-3 text-xs',
  md: 'h-9 gap-1.5 rounded-lg px-4 text-sm',
  lg: 'h-11 gap-2 rounded-lg px-5 text-sm',
}

const iconSizes: Record<ButtonSize, string> = {
  sm: 'size-8 rounded-md',
  md: 'size-9 rounded-lg',
  lg: 'size-11 rounded-lg',
}

const classes = computed(() => [
  'inline-flex shrink-0 select-none items-center justify-center whitespace-nowrap font-medium transition-colors',
  'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
  variants[props.variant],
  props.icon ? iconSizes[props.size] : sizes[props.size],
  props.block && 'w-full',
])

const isDisabled = computed(() => props.disabled || props.loading)
</script>

<template>
  <component
    :is="to ? NuxtLink : 'button'"
    :to="to"
    :type="to ? undefined : type"
    :disabled="to ? undefined : isDisabled"
    :aria-disabled="to && isDisabled ? 'true' : undefined"
    :aria-busy="loading || undefined"
    :class="classes"
  >
    <UiSpinner
      v-if="loading"
      :size="size === 'sm' ? 14 : 16"
    />
    <slot
      v-else
      name="leading"
    />
    <slot />
    <slot name="trailing" />
  </component>
</template>
