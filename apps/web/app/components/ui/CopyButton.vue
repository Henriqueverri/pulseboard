<script setup lang="ts">
import { PhCheck, PhCopy } from '@phosphor-icons/vue'

const props = withDefaults(defineProps<{
  value: string
  /** Accessible name, e.g. "Copiar ID externo". */
  label: string
  /** Visible text; icon-only when omitted. */
  text?: string
  variant?: 'ghost' | 'outline' | 'primary'
  size?: 'sm' | 'md'
}>(), {
  text: undefined,
  variant: 'ghost',
  size: 'sm',
})

const emit = defineEmits<{ copied: [], failed: [] }>()

const copied = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

async function copy() {
  try {
    await navigator.clipboard.writeText(props.value)
  }
  catch {
    emit('failed')

    return
  }

  copied.value = true
  emit('copied')
  clearTimeout(timer)
  timer = setTimeout(() => {
    copied.value = false
  }, 2000)
}

onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <UiButton
    :variant="variant"
    :size="size"
    :icon="!text"
    :aria-label="text ? undefined : (copied ? 'Copiado' : label)"
    :title="text ? undefined : label"
    @click="copy"
  >
    <template #leading>
      <PhCheck
        v-if="copied"
        :size="14"
      />
      <PhCopy
        v-else
        :size="14"
      />
    </template>
    <template v-if="text">
      {{ copied ? 'Copiado' : text }}
    </template>
    <span
      class="sr-only"
      aria-live="polite"
    >{{ copied ? 'Copiado para a área de transferência' : '' }}</span>
  </UiButton>
</template>
