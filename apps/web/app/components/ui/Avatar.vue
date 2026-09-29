<script setup lang="ts">
const props = withDefaults(defineProps<{ name: string, size?: 'sm' | 'md' | 'lg' }>(), { size: 'md' })

// Deterministic soft background from the SnowUI secondary palette.
const palettes = [
  'bg-brand-100 text-brand-700',
  'bg-info-soft text-info-strong',
  'bg-success-soft text-success-strong',
  'bg-warning-soft text-warning-strong',
  'bg-purple/25 text-ink',
]

const palette = computed(() => {
  let hash = 0
  for (const char of props.name) {
    hash = (hash * 31 + char.charCodeAt(0)) >>> 0
  }

  return palettes[hash % palettes.length]
})
</script>

<template>
  <span
    class="inline-flex shrink-0 select-none items-center justify-center rounded-full font-semibold"
    :class="[
      palette,
      size === 'sm' ? 'size-6 text-[10px]' : size === 'lg' ? 'size-12 text-base' : 'size-8 text-xs',
    ]"
    aria-hidden="true"
  >
    {{ initials(name) }}
  </span>
</template>
