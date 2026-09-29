<script setup lang="ts" generic="S extends string">
import type { SegmentOption } from '~/components/ui/SegmentedControl.vue'

const props = defineProps<{
  sortOptions: SegmentOption<S>[]
  sort: S
  limit: number
}>()

const emit = defineEmits<{
  'update:sort': [value: S]
  'update:limit': [value: number]
}>()

const limitOptions = computed(() => {
  const values = new Set<number>([...RANKING_LIMITS, props.limit])

  return [...values].sort((a, b) => a - b).map(value => ({ value: String(value), label: `Top ${value}` }))
})

const sortModel = computed({
  get: () => props.sort,
  set: value => emit('update:sort', value),
})

const limitModel = computed({
  get: () => String(props.limit),
  set: value => emit('update:limit', Number(value)),
})
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <UiSegmentedControl
      v-model="sortModel"
      :options="sortOptions"
      label="Ordenar ranking por"
    />
    <div class="w-28">
      <UiSelect
        v-model="limitModel"
        :options="limitOptions"
        label="Quantidade no ranking"
        size="sm"
      />
    </div>
  </div>
</template>
