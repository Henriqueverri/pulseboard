<script setup lang="ts">
import type { ReportingPeriod } from '~/composables/useReportingPeriod'
import type { PeriodPreset } from '~/utils/period'

const props = defineProps<{
  period: ReportingPeriod
}>()

type Choice = PeriodPreset | 'custom'

const options = [...PERIOD_PRESETS, 'custom' as const].map(value => ({ value, label: PRESET_LABELS[value] }))

const editing = ref(false)
const draftFrom = ref('')
const draftTo = ref('')
const error = ref<string | null>(null)

const choice = computed<Choice>({
  get: () => editing.value ? 'custom' : props.period.preset.value,
  set: (value) => {
    if (value === 'custom') {
      draftFrom.value = props.period.range.value.from
      draftTo.value = props.period.range.value.to
      error.value = null
      editing.value = true

      return
    }

    editing.value = false
    props.period.setPreset(value)
  },
})

const showRange = computed(() => editing.value || props.period.preset.value === 'custom')

watch(() => props.period.range.value, (range) => {
  if (!editing.value) {
    draftFrom.value = range.from
    draftTo.value = range.to
  }
}, { immediate: true })

watch([draftFrom, draftTo], () => {
  error.value = null
})

async function apply() {
  error.value = await props.period.setCustomRange(draftFrom.value, draftTo.value)

  if (!error.value) {
    editing.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <form
      class="flex flex-wrap items-center gap-2"
      novalidate
      @submit.prevent="apply"
    >
      <div class="w-44">
        <UiSelect
          v-model="choice"
          :options="options"
          label="Período"
        />
      </div>
      <template v-if="showRange">
        <UiDateRangeInput
          v-model:from="draftFrom"
          v-model:to="draftTo"
          :max="period.today.value"
          :invalid="Boolean(error)"
          from-label="Início do período"
          to-label="Fim do período"
        />
        <UiButton
          type="submit"
          variant="outline"
          :disabled="draftFrom === period.range.value.from && draftTo === period.range.value.to && !editing"
        >
          Aplicar
        </UiButton>
      </template>
    </form>
    <p
      v-if="error"
      class="text-xs text-danger-strong"
      role="alert"
    >
      {{ error }}
    </p>
  </div>
</template>
