<script setup lang="ts">
defineProps<{
  label: string
  value: string
  hint?: string
}>()
</script>

<template>
  <div class="rounded-2xl border border-ink/[0.06] bg-surface p-5">
    <p class="text-sm text-ink/65">
      {{ label }}
    </p>
    <p class="mt-2 text-2xl font-semibold tracking-tight text-ink tabular">
      {{ value }}
    </p>
    <p
      v-if="hint"
      class="mt-1 text-xs text-ink/65"
    >
      {{ hint }}
    </p>
  </div>
</template>
