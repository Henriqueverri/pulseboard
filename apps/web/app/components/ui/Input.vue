<script setup lang="ts">
defineOptions({ inheritAttrs: false })

withDefaults(defineProps<{
  invalid?: boolean
  size?: 'sm' | 'md' | 'lg'
  describedBy?: string
}>(), {
  invalid: false,
  size: 'md',
  describedBy: undefined,
})

const model = defineModel<string>({ default: '' })
const input = useTemplateRef<HTMLInputElement>('input')

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <div
    class="flex w-full items-center gap-2 rounded-lg border bg-surface px-3 text-sm transition-colors focus-within:ring-2"
    :class="[
      invalid
        ? 'border-danger-strong/60 focus-within:ring-danger/20'
        : 'border-ink/10 hover:border-ink/20 focus-within:border-brand-500 focus-within:ring-brand-500/15',
      size === 'sm' ? 'h-8' : size === 'lg' ? 'h-11' : 'h-9',
      $attrs.disabled !== undefined && $attrs.disabled !== false ? 'bg-ink/[0.03] text-ink/65' : '',
      $attrs.class,
    ]"
  >
    <span
      v-if="$slots.leading"
      class="flex shrink-0 items-center text-ink/65"
    >
      <slot name="leading" />
    </span>
    <input
      ref="input"
      v-model="model"
      v-bind="{ ...$attrs, class: undefined }"
      :aria-invalid="invalid || undefined"
      :aria-describedby="describedBy"
      class="h-full w-full min-w-0 bg-transparent text-ink outline-none placeholder:text-ink/40 focus-visible:ring-0 focus-visible:ring-offset-0 disabled:cursor-not-allowed"
    >
    <span
      v-if="$slots.trailing"
      class="flex shrink-0 items-center text-ink/65"
    >
      <slot name="trailing" />
    </span>
  </div>
</template>
