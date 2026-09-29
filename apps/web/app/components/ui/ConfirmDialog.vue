<script setup lang="ts">
import {
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogOverlay,
  AlertDialogPortal,
  AlertDialogRoot,
  AlertDialogTitle,
} from 'reka-ui'

withDefaults(defineProps<{
  title: string
  description?: string
  confirmLabel?: string
  cancelLabel?: string
  tone?: 'danger' | 'default'
  loading?: boolean
}>(), {
  description: undefined,
  confirmLabel: 'Confirmar',
  cancelLabel: 'Cancelar',
  tone: 'default',
  loading: false,
})

const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ confirm: [] }>()
</script>

<template>
  <AlertDialogRoot v-model:open="open">
    <AlertDialogPortal>
      <AlertDialogOverlay class="fixed inset-0 z-50 bg-ink/30 backdrop-blur-[2px] data-[state=open]:animate-fade-in" />
      <AlertDialogContent
        class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-surface p-6 shadow-pop outline-none data-[state=open]:animate-dialog-in"
        @escape-key-down="loading && $event.preventDefault()"
      >
        <AlertDialogTitle class="text-base font-semibold text-ink">
          {{ title }}
        </AlertDialogTitle>
        <AlertDialogDescription
          v-if="description || $slots.default"
          as="div"
          class="mt-2 text-sm text-ink/65"
        >
          <slot>{{ description }}</slot>
        </AlertDialogDescription>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <AlertDialogCancel as-child>
            <UiButton
              variant="outline"
              :disabled="loading"
            >
              {{ cancelLabel }}
            </UiButton>
          </AlertDialogCancel>
          <UiButton
            :variant="tone === 'danger' ? 'danger' : 'primary'"
            :loading="loading"
            @click="emit('confirm')"
          >
            {{ confirmLabel }}
          </UiButton>
        </div>
      </AlertDialogContent>
    </AlertDialogPortal>
  </AlertDialogRoot>
</template>
