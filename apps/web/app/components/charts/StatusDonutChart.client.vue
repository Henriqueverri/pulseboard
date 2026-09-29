<script setup lang="ts">
import { ArcElement, Chart, Tooltip } from 'chart.js'
import type { ChartData, ChartOptions } from 'chart.js'
import { Doughnut } from 'vue-chartjs'

Chart.register(ArcElement, Tooltip)

export interface DonutSlice {
  label: string
  value: number
  /** Design token name (`--pb-*`). */
  color: string
}

const props = defineProps<{
  slices: DonutSlice[]
  /** Accessible description; the visible legend lives next to the chart. */
  label: string
}>()

const data = computed<ChartData<'doughnut'>>(() => ({
  labels: props.slices.map(slice => slice.label),
  datasets: [{
    data: props.slices.map(slice => slice.value),
    backgroundColor: props.slices.map(slice => tokenColor(slice.color)),
    borderColor: tokenColor('surface'),
    borderWidth: 2,
    hoverOffset: 4,
  }],
}))

const options = computed<ChartOptions<'doughnut'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  cutout: '70%',
  animation: { duration: 300 },
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: tokenColor('ink', 0.92),
      padding: 10,
      cornerRadius: 8,
      bodyFont: { family: CHART_FONT },
      callbacks: {
        label: item => ` ${item.label}: ${formatInteger(Number(item.raw))}`,
      },
    },
  },
}))
</script>

<template>
  <div
    class="relative size-full"
    role="img"
    :aria-label="label"
  >
    <Doughnut
      :data="data"
      :options="options"
      aria-hidden="true"
    />
    <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
      <slot />
    </div>
  </div>
</template>
