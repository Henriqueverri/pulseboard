<script setup lang="ts">
import { PhCheckCircle, PhInfo, PhWarningCircle, PhX } from '@phosphor-icons/vue'
import { ToastClose, ToastDescription, ToastProvider, ToastRoot, ToastTitle, ToastViewport } from 'reka-ui'

const { toasts, dismiss } = useToast()

const icons = { success: PhCheckCircle, danger: PhWarningCircle, info: PhInfo }
</script>

<template>
  <ToastProvider
    :duration="5000"
    swipe-direction="right"
  >
    <ToastRoot
      v-for="toast in toasts"
      :key="toast.id"
      :type="toast.tone === 'danger' ? 'foreground' : 'background'"
      class="flex items-start gap-3 rounded-xl bg-ink px-4 py-3 text-white shadow-pop data-[state=open]:animate-toast-in data-[swipe=move]:translate-x-[var(--reka-toast-swipe-move-x)]"
      @update:open="(open: boolean) => !open && dismiss(toast.id)"
    >
      <component
        :is="icons[toast.tone]"
        :size="18"
        weight="fill"
        class="mt-px shrink-0"
        :class="{ 'text-success': toast.tone === 'success', 'text-danger': toast.tone === 'danger', 'text-info': toast.tone === 'info' }"
        aria-hidden="true"
      />
      <div class="min-w-0 flex-1">
        <ToastTitle class="text-sm font-medium">
          {{ toast.title }}
        </ToastTitle>
        <ToastDescription
          v-if="toast.description"
          class="mt-0.5 text-xs text-white/70"
        >
          {{ toast.description }}
        </ToastDescription>
      </div>
      <ToastClose
        class="-mr-1 rounded p-1 text-white/60 hover:bg-white/10 hover:text-white"
        aria-label="Fechar"
      >
        <PhX :size="14" />
      </ToastClose>
    </ToastRoot>
    <ToastViewport class="fixed bottom-0 right-0 z-[70] flex w-full max-w-sm flex-col gap-2 p-4 outline-none sm:bottom-2 sm:right-2" />
  </ToastProvider>
</template>
