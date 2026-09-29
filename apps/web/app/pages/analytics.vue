<script setup lang="ts">
const tabs = [
  { label: 'Receita', to: '/analytics/revenue', match: '/analytics/revenue' },
  { label: 'Produtos', to: '/analytics/products', match: '/analytics/products' },
  { label: 'Clientes', to: '/analytics/customers', match: '/analytics/customers' },
  { label: 'Transações', to: '/analytics/transactions', match: '/analytics/transactions' },
]
</script>

<template>
  <div>
    <PageHeader
      title="Analytics"
      description="Métricas de vendas calculadas a partir das transações pagas."
    />
    <UiTabsNav
      :tabs="tabs"
      label="Seções de analytics"
      class="mb-6"
    />
    <NuxtPage />
  </div>
</template>
