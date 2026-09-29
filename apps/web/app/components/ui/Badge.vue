<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'neutral' | 'brand' | 'inverse' }>(), { tone: 'neutral' })
</script>

<template>
  <span
    class="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-1.5 text-[11px] font-semibold tabular"
    :class="{
      'bg-ink/[0.07] text-ink/70': tone === 'neutral',
      'bg-brand-600 text-white': tone === 'brand',
      'bg-ink text-white': tone === 'inverse',
    }"
  >
    <slot />
  </span>
</template>
