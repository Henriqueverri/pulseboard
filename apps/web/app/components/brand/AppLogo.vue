<script setup lang="ts">
withDefaults(defineProps<{
  /** `mark` hides the wordmark (collapsed sidebar). */
  variant?: 'full' | 'mark'
  inverted?: boolean
}>(), {
  variant: 'full',
  inverted: false,
})
</script>

<template>
  <span class="inline-flex items-center gap-2.5">
    <span
      class="flex size-8 shrink-0 items-center justify-center rounded-lg"
      :class="inverted ? 'bg-white text-ink' : 'bg-ink text-white'"
      aria-hidden="true"
    >
      <svg
        viewBox="0 0 24 24"
        class="size-5"
        fill="none"
        stroke="currentColor"
        stroke-width="2.2"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path d="M3 12h4l2.5-6 5 12 2.5-6h4" />
      </svg>
    </span>
    <span
      v-if="variant === 'full'"
      class="text-[15px] font-semibold tracking-tight"
      :class="inverted ? 'text-white' : 'text-ink'"
    >PulseBoard</span>
    <span
      v-else
      class="sr-only"
    >PulseBoard</span>
  </span>
</template>
