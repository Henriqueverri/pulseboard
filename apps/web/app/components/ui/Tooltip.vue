<script setup lang="ts">
import { TooltipContent, TooltipPortal, TooltipRoot, TooltipTrigger } from 'reka-ui'

withDefaults(defineProps<{
  content: string
  side?: 'top' | 'right' | 'bottom' | 'left'
  disabled?: boolean
}>(), {
  side: 'top',
  disabled: false,
})
</script>

<template>
  <slot v-if="disabled" />
  <TooltipRoot v-else>
    <TooltipTrigger as-child>
      <slot />
    </TooltipTrigger>
    <TooltipPortal>
      <TooltipContent
        :side="side"
        :side-offset="6"
        class="z-[60] max-w-xs rounded-md bg-ink px-2.5 py-1.5 text-xs leading-4 text-white shadow-pop data-[state=delayed-open]:animate-fade-in"
      >
        {{ content }}
      </TooltipContent>
    </TooltipPortal>
  </TooltipRoot>
</template>
