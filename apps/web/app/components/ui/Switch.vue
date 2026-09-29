<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from 'reka-ui'

withDefaults(defineProps<{ id?: string, disabled?: boolean, label?: string }>(), {
  id: undefined,
  disabled: false,
  label: undefined,
})

const model = defineModel<boolean>({ default: false })
</script>

<template>
  <SwitchRoot
    :id="id"
    v-model="model"
    :disabled="disabled"
    :aria-label="label"
    class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full bg-ink/15 transition-colors data-[state=checked]:bg-ink disabled:cursor-not-allowed disabled:opacity-50"
  >
    <SwitchThumb class="block size-4 translate-x-0.5 rounded-full bg-white shadow-soft transition-transform data-[state=checked]:translate-x-[18px]" />
  </SwitchRoot>
</template>
