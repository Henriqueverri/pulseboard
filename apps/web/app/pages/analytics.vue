<script setup lang="ts">
const period = useReportingPeriod()

const SECTIONS = [
  { label: 'Receita', path: '/analytics/revenue' },
  { label: 'Produtos', path: '/analytics/products' },
  { label: 'Clientes', path: '/analytics/customers' },
  { label: 'Transações', path: '/analytics/transactions' },
]

/** Switching tabs keeps the period; tab-specific keys (sort, limit, granularity) stay behind. */
const tabs = computed(() => SECTIONS.map(section => ({
  label: section.label,
  to: { path: section.path, query: period.query.value },
  match: section.path,
})))

const caption = computed(() => {
  const range = period.range.value
  const previous = previousRange(range)

  return `${formatCivilRange(range.from, range.to)} · comparado a ${formatCivilRange(previous.from, previous.to)}`
})
</script>

<template>
  <div>
    <PageHeader title="Analytics">
      <template #description>
        <span class="tabular">{{ caption }}</span>
      </template>
      <template #actions>
        <PeriodFilter :period="period" />
      </template>
    </PageHeader>
    <UiTabsNav
      :tabs="tabs"
      label="Seções de analytics"
      class="mb-6"
    />
    <NuxtPage />
  </div>
</template>
