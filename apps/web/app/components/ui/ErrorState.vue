<script setup lang="ts">
import { PhArrowCounterClockwise, PhWarningCircle } from '@phosphor-icons/vue'

withDefaults(defineProps<{
  title?: string
  message?: string
  compact?: boolean
  retrying?: boolean
  retryable?: boolean
  /** `X-Request-Id` of the failed request, for support. */
  requestId?: string | null
}>(), {
  title: 'Não foi possível carregar os dados',
  message: undefined,
  requestId: null,
  compact: false,
  retrying: false,
  retryable: true,
})

const emit = defineEmits<{ retry: [] }>()
</script>

<template>
  <div
    class="flex flex-col items-center justify-center text-center"
    :class="compact ? 'gap-2 px-4 py-8' : 'gap-3 px-6 py-14'"
    role="alert"
  >
    <span
      class="flex items-center justify-center rounded-xl bg-danger-soft text-danger-strong"
      :class="compact ? 'size-9' : 'size-11'"
      aria-hidden="true"
    >
      <PhWarningCircle :size="compact ? 18 : 22" />
    </span>
    <div class="max-w-sm">
      <p class="text-sm font-semibold text-ink">
        {{ title }}
      </p>
      <p
        v-if="message"
        class="mt-1 text-xs text-ink/65"
      >
        {{ message }}
      </p>
      <p
        v-if="requestId"
        class="mt-2 text-[11px] text-ink/65"
      >
        ID da requisição: <span class="select-all font-mono">{{ requestId }}</span>
      </p>
    </div>
    <UiButton
      v-if="retryable"
      size="sm"
      variant="outline"
      :loading="retrying"
      @click="emit('retry')"
    >
      <template #leading>
        <PhArrowCounterClockwise :size="14" />
      </template>
      Tentar novamente
    </UiButton>
  </div>
</template>
