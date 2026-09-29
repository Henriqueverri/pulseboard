<script setup lang="ts">
/**
 * Amount input bound to the API decimal string ("1234.50").
 * The user types in pt-BR ("1234,50"); `null` means empty or not a valid amount.
 */
withDefaults(defineProps<{
  invalid?: boolean
  describedBy?: string
  currencySymbol?: string
}>(), {
  invalid: false,
  describedBy: undefined,
  currencySymbol: 'R$',
})

const model = defineModel<string | null>({ default: null })
const text = ref(fromMoneyString(model.value))

watch(model, (value) => {
  if (value !== toMoneyString(text.value)) {
    text.value = fromMoneyString(value)
  }
})

function onInput(value: string) {
  text.value = value.replace(/[^\d.,]/g, '')
  model.value = toMoneyString(text.value)
}

function onBlur() {
  const parsed = toMoneyString(text.value)
  if (parsed !== null) {
    text.value = fromMoneyString(parsed)
  }
}
</script>

<template>
  <UiInput
    :model-value="text"
    inputmode="decimal"
    autocomplete="off"
    placeholder="0,00"
    :invalid="invalid"
    :described-by="describedBy"
    class="tabular"
    @update:model-value="onInput"
    @blur="onBlur"
  >
    <template #leading>
      <span class="text-sm text-ink/65">{{ currencySymbol }}</span>
    </template>
  </UiInput>
</template>
