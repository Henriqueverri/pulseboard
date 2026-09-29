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
} from 'reka-ui'

withDefaults(defineProps<{
  title: string
  description?: string
  size?: 'sm' | 'md' | 'lg'
  /** Prevents closing by overlay click / Esc while a request is running. */
  persistent?: boolean
}>(), {
  description: undefined,
  size: 'md',
  persistent: false,
})

const open = defineModel<boolean>('open', { default: false })

function guard(event: Event, persistent: boolean) {
  if (persistent) {
    event.preventDefault()
  }
}
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-50 bg-ink/30 backdrop-blur-[2px] data-[state=open]:animate-fade-in" />
      <DialogContent
        class="fixed inset-x-0 bottom-0 z-50 flex max-h-[92dvh] flex-col rounded-t-2xl bg-surface shadow-pop outline-none data-[state=open]:animate-sheet-in sm:inset-auto sm:left-1/2 sm:top-1/2 sm:max-h-[85vh] sm:w-[calc(100%-2rem)] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-2xl sm:data-[state=open]:animate-dialog-in"
        :class="{ 'sm:max-w-sm': size === 'sm', 'sm:max-w-lg': size === 'md', 'sm:max-w-2xl': size === 'lg' }"
        @escape-key-down="guard($event, persistent)"
        @pointer-down-outside="guard($event, persistent)"
        @interact-outside="guard($event, persistent)"
      >
        <header class="flex items-start justify-between gap-4 px-5 pb-2 pt-5 sm:px-6 sm:pt-6">
          <div class="min-w-0">
            <DialogTitle class="text-base font-semibold text-ink">
              {{ title }}
            </DialogTitle>
            <DialogDescription
              v-if="description"
              class="mt-1 text-sm text-ink/60"
            >
              {{ description }}
            </DialogDescription>
          </div>
          <DialogClose
            v-if="!persistent"
            class="-mr-1.5 -mt-1 rounded-md p-1.5 text-ink/50 hover:bg-ink/5 hover:text-ink"
            aria-label="Fechar"
          >
            <PhX :size="18" />
          </DialogClose>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-3 sm:px-6">
          <slot />
        </div>
        <footer
          v-if="$slots.footer"
          class="flex flex-col-reverse gap-2 border-t border-ink/[0.07] px-5 py-4 sm:flex-row sm:justify-end sm:px-6"
        >
          <slot name="footer" />
        </footer>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
