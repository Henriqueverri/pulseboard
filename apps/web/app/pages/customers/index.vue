<script setup lang="ts">
useHead({ title: 'Clientes · PulseBoard' })
</script>

<template>
  <div>
    <PageHeader
      title="Clientes"
      description="Clientes que compram da organização."
    />
    <UiCard padding="none">
      <UiEmptyState
        title="Em construção"
        description="A listagem de clientes chega na próxima etapa."
      />
    </UiCard>
  </div>
</template>
