<script setup lang="ts">
import { PhEye, PhEyeSlash } from '@phosphor-icons/vue'

defineOptions({ inheritAttrs: false })

defineProps<{
  invalid?: boolean
  describedBy?: string
}>()

const model = defineModel<string>({ default: '' })
const visible = ref(false)
</script>

<template>
  <UiInput
    v-bind="$attrs"
    v-model="model"
    :type="visible ? 'text' : 'password'"
    :invalid="invalid"
    :described-by="describedBy"
    size="lg"
  >
    <template #trailing>
      <button
        type="button"
        class="-mr-1 flex size-7 items-center justify-center rounded-md text-ink/45 hover:bg-ink/[0.05] hover:text-ink"
        :aria-label="visible ? 'Ocultar senha' : 'Mostrar senha'"
        :aria-pressed="visible"
        @click="visible = !visible"
      >
        <component
          :is="visible ? PhEyeSlash : PhEye"
          :size="16"
        />
      </button>
    </template>
  </UiInput>
</template>
