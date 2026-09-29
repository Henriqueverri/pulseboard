<script setup lang="ts">
useHead({ title: 'Produtos · PulseBoard' })
</script>

<template>
  <div>
    <PageHeader
      title="Produtos"
      description="Catálogo de produtos da organização."
    />
    <UiCard padding="none">
      <UiEmptyState
        title="Em construção"
        description="A listagem de produtos chega na próxima etapa."
      />
    </UiCard>
  </div>
</template>
