<script setup lang="ts">
const props = withDefaults(defineProps<{
  label: string
  error?: string | null
  hint?: string
  required?: boolean
  optional?: boolean
}>(), {
  error: null,
  hint: undefined,
  required: false,
  optional: false,
})

const id = useId()
const hintId = `${id}-hint`
const errorId = `${id}-error`

const describedBy = computed(() => [
  props.hint ? hintId : null,
  props.error ? errorId : null,
].filter(Boolean).join(' ') || undefined)
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label
      :for="id"
      class="flex items-baseline gap-1 text-sm font-medium text-ink"
    >
      {{ label }}
      <span
        v-if="required"
        class="text-danger-strong"
        aria-hidden="true"
      >*</span>
      <span
        v-else-if="optional"
        class="text-xs font-normal text-ink/50"
      >(opcional)</span>
    </label>
    <slot
      :id="id"
      :described-by="describedBy"
      :invalid="Boolean(error)"
    />
    <p
      v-if="error"
      :id="errorId"
      class="text-xs text-danger-strong"
      role="alert"
    >
      {{ error }}
    </p>
    <p
      v-else-if="hint"
      :id="hintId"
      class="text-xs text-ink/55"
    >
      {{ hint }}
    </p>
  </div>
</template>
