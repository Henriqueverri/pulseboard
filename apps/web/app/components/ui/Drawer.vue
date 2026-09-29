<script setup lang="ts">
import { PhX } from '@phosphor-icons/vue'
import {
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
  VisuallyHidden,
} from 'reka-ui'

withDefaults(defineProps<{
  title: string
  description?: string
  side?: 'left' | 'right' | 'bottom'
  /** Visually hide the header (the title stays available to screen readers). */
  hideHeader?: boolean
}>(), {
  description: undefined,
  side: 'right',
  hideHeader: false,
})

const open = defineModel<boolean>('open', { default: false })
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-50 bg-ink/30 backdrop-blur-[2px] data-[state=open]:animate-fade-in" />
      <DialogContent
        class="fixed z-50 flex flex-col bg-surface shadow-pop outline-none"
        :class="{
          'inset-y-0 left-0 w-[min(300px,85vw)] data-[state=open]:animate-drawer-left': side === 'left',
          'inset-y-0 right-0 w-[min(380px,92vw)] data-[state=open]:animate-drawer-right': side === 'right',
          'inset-x-0 bottom-0 max-h-[88dvh] rounded-t-2xl data-[state=open]:animate-sheet-in': side === 'bottom',
        }"
      >
        <VisuallyHidden v-if="hideHeader">
          <DialogTitle>{{ title }}</DialogTitle>
          <DialogDescription v-if="description">
            {{ description }}
          </DialogDescription>
        </VisuallyHidden>
        <header
          v-else
          class="flex items-start justify-between gap-4 border-b border-ink/[0.07] px-5 py-4"
        >
          <div class="min-w-0">
            <DialogTitle class="text-base font-semibold text-ink">
              {{ title }}
            </DialogTitle>
            <DialogDescription
              v-if="description"
              class="mt-0.5 text-sm text-ink/65"
            >
              {{ description }}
            </DialogDescription>
          </div>
          <DialogClose
            class="-mr-1.5 rounded-md p-1.5 text-ink/65 hover:bg-ink/5 hover:text-ink"
            aria-label="Fechar"
          >
            <PhX :size="18" />
          </DialogClose>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto">
          <slot />
        </div>
        <footer
          v-if="$slots.footer"
          class="flex gap-2 border-t border-ink/[0.07] px-5 py-4"
        >
          <slot name="footer" />
        </footer>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
