<script setup lang="ts">
useHead({ title: 'Dashboard · PulseBoard' })

const { organization } = useOrganization()
</script>

<template>
  <div>
    <PageHeader
      title="Dashboard"
      :description="organization?.name"
    />
    <UiCard padding="none">
      <UiEmptyState
        title="Em construção"
        description="Os indicadores do dashboard chegam na etapa de dashboard."
      />
    </UiCard>
  </div>
</template>
