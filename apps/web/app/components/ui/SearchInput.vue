<script setup lang="ts">
import { PhMagnifyingGlass, PhX } from '@phosphor-icons/vue'
import { useDebounceFn } from '@vueuse/core'

const props = withDefaults(defineProps<{
  placeholder?: string
  label?: string
  debounce?: number
  size?: 'sm' | 'md'
}>(), {
  placeholder: 'Buscar…',
  label: 'Buscar',
  debounce: 300,
  size: 'md',
})

const model = defineModel<string>({ default: '' })
const text = ref(model.value)

watch(model, (value) => {
  if (value !== text.value) {
    text.value = value
  }
})

const commit = useDebounceFn((value: string) => {
  model.value = value.trim()
}, () => props.debounce)

function onInput(value: string) {
  text.value = value
  commit(value)
}

function clear() {
  text.value = ''
  model.value = ''
}
</script>

<template>
  <UiInput
    :model-value="text"
    type="search"
    :size="size"
    :placeholder="placeholder"
    :aria-label="label"
    autocomplete="off"
    enterkeyhint="search"
    @update:model-value="onInput"
    @keydown.esc="clear"
  >
    <template #leading>
      <PhMagnifyingGlass :size="16" />
    </template>
    <template
      v-if="text"
      #trailing
    >
      <button
        type="button"
        class="rounded p-0.5 text-ink/45 hover:bg-ink/5 hover:text-ink"
        aria-label="Limpar busca"
        @click="clear"
      >
        <PhX :size="14" />
      </button>
    </template>
  </UiInput>
</template>

<style scoped>
:deep(input[type='search']::-webkit-search-cancel-button) {
  display: none;
}
</style>
