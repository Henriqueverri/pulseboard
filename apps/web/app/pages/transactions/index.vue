<script setup lang="ts">
useHead({ title: 'Transações · PulseBoard' })
</script>

<template>
  <div>
    <PageHeader
      title="Transações"
      description="Pedidos registrados na organização."
    />
    <UiCard padding="none">
      <UiEmptyState
        title="Em construção"
        description="A listagem de transações chega na próxima etapa."
      />
    </UiCard>
  </div>
</template>
