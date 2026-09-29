<script setup lang="ts">
export type TagTone = 'neutral' | 'success' | 'warning' | 'info' | 'danger' | 'brand'

withDefaults(defineProps<{
  tone?: TagTone
  dot?: boolean
  size?: 'sm' | 'md'
}>(), {
  tone: 'neutral',
  dot: false,
  size: 'md',
})

// SnowUI Tag: 12/16 text, 4px radius-ish pill, soft background, optional leading dot.
const tones: Record<TagTone, { body: string, dot: string }> = {
  neutral: { body: 'bg-ink/[0.05] text-ink/70', dot: 'bg-ink/40' },
  success: { body: 'bg-success-soft text-success-strong', dot: 'bg-success' },
  warning: { body: 'bg-warning-soft text-warning-strong', dot: 'bg-warning' },
  info: { body: 'bg-info-soft text-info-strong', dot: 'bg-info' },
  danger: { body: 'bg-danger-soft text-danger-strong', dot: 'bg-danger' },
  brand: { body: 'bg-brand-50 text-brand-700', dot: 'bg-brand-500' },
}
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md font-medium"
    :class="[tones[tone].body, size === 'sm' ? 'h-5 px-1.5 text-[11px]' : 'h-6 px-2 text-xs']"
  >
    <span
      v-if="dot"
      class="size-1.5 rounded-full"
      :class="tones[tone].dot"
      aria-hidden="true"
    />
    <slot />
  </span>
</template>
