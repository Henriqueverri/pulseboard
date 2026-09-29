<script setup lang="ts">
import {
  CategoryScale,
  Chart,
  Filler,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js'
import type { ChartData, ChartOptions } from 'chart.js'
import { Line } from 'vue-chartjs'
import type { RevenueBucket } from '~/types/analytics'
import type { Granularity } from '~/utils/period'

Chart.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip)

const props = withDefaults(defineProps<{
  buckets: RevenueBucket[]
  granularity: Granularity
  currency: string
  metric?: 'revenue' | 'orders'
  /** Screen-reader table of the buckets; turn off when the page shows an equivalent table. */
  srTable?: boolean
}>(), {
  metric: 'revenue',
  srTable: true,
})

const data = computed<ChartData<'line'>>(() => ({
  labels: props.buckets.map(bucket => bucketAxisLabel(bucket, props.granularity)),
  datasets: [{
    label: props.metric === 'revenue' ? 'Receita' : 'Pedidos',
    data: props.buckets.map(bucket => props.metric === 'revenue' ? Number(bucket.revenue) : bucket.orders),
    borderColor: tokenColor('brand-500'),
    borderWidth: 2,
    cubicInterpolationMode: 'monotone',
    fill: true,
    backgroundColor: (context) => {
      const { ctx, chartArea } = context.chart

      if (!chartArea) {
        return tokenColor('brand-500', 0.08)
      }

      const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
      gradient.addColorStop(0, tokenColor('brand-500', 0.22))
      gradient.addColorStop(1, tokenColor('brand-500', 0))

      return gradient
    },
    pointRadius: props.buckets.length > 45 ? 0 : 2.5,
    pointHoverRadius: 5,
    pointBackgroundColor: tokenColor('brand-500'),
  }],
}))

const options = computed<ChartOptions<'line'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  animation: { duration: 300 },
  interaction: { mode: 'index', intersect: false },
  font: { family: CHART_FONT },
  scales: {
    x: {
      grid: { display: false },
      border: { display: false },
      ticks: { color: tokenColor('ink', 0.45), maxRotation: 0, autoSkipPadding: 16, font: { size: 11, family: CHART_FONT } },
    },
    y: {
      beginAtZero: true,
      grid: { color: tokenColor('ink', 0.06) },
      border: { display: false },
      ticks: {
        color: tokenColor('ink', 0.45),
        maxTicksLimit: 5,
        font: { size: 11, family: CHART_FONT },
        precision: 0,
        callback: value => props.metric === 'revenue'
          ? formatMoney(Number(value), props.currency, { compact: true })
          : formatCompactNumber(Number(value)),
      },
    },
  },
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: tokenColor('ink', 0.92),
      padding: 10,
      cornerRadius: 8,
      displayColors: false,
      titleFont: { family: CHART_FONT, weight: 500 },
      bodyFont: { family: CHART_FONT },
      callbacks: {
        title: items => items[0] ? bucketRangeLabel(props.buckets[items[0].dataIndex]!, props.granularity) : '',
        label: (item) => {
          const bucket = props.buckets[item.dataIndex]!

          return [`Receita: ${formatMoney(bucket.revenue, props.currency)}`, `Pedidos: ${formatInteger(bucket.orders)}`]
        },
      },
    },
  },
}))

const summary = computed(() => {
  const first = props.buckets[0]
  const last = props.buckets[props.buckets.length - 1]

  return first && last
    ? `Gráfico de ${props.metric === 'revenue' ? 'receita' : 'pedidos'} de ${formatCivilRange(first.from, last.to)}, ${props.buckets.length} pontos. Valores detalhados na tabela.`
    : 'Gráfico sem dados.'
})
</script>

<template>
  <div>
    <div
      class="relative h-64 sm:h-72"
      role="img"
      :aria-label="summary"
    >
      <Line
        :data="data"
        :options="options"
        aria-hidden="true"
      />
    </div>
    <table
      v-if="srTable"
      class="sr-only"
    >
      <caption>Receita por período</caption>
      <thead>
        <tr>
          <th scope="col">
            Período
          </th>
          <th scope="col">
            Receita
          </th>
          <th scope="col">
            Pedidos
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="bucket in buckets"
          :key="bucket.bucket"
        >
          <th scope="row">
            {{ bucketRangeLabel(bucket, granularity) }}
          </th>
          <td>{{ formatMoney(bucket.revenue, currency) }}</td>
          <td>{{ formatInteger(bucket.orders) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
