<script setup lang="ts">
import { PhTray } from '@phosphor-icons/vue'
import type { Component } from 'vue'

withDefaults(defineProps<{
  title: string
  description?: string
  icon?: Component
  compact?: boolean
}>(), {
  description: undefined,
  icon: undefined,
  compact: false,
})
</script>

<template>
  <div
    class="flex flex-col items-center justify-center text-center"
    :class="compact ? 'gap-2 px-4 py-8' : 'gap-3 px-6 py-14'"
  >
    <span
      class="flex items-center justify-center rounded-xl bg-ink/[0.04] text-ink/40"
      :class="compact ? 'size-9' : 'size-11'"
      aria-hidden="true"
    >
      <component
        :is="icon ?? PhTray"
        :size="compact ? 18 : 22"
      />
    </span>
    <div class="max-w-sm">
      <p class="text-sm font-semibold text-ink">
        {{ title }}
      </p>
      <p
        v-if="description"
        class="mt-1 text-xs text-ink/55"
      >
        {{ description }}
      </p>
    </div>
    <div
      v-if="$slots.default"
      class="mt-1 flex flex-wrap justify-center gap-2"
    >
      <slot />
    </div>
  </div>
</template>
