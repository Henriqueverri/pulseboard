<script setup lang="ts">
useHead({ title: 'Dashboard · PulseBoard' })

const { user, logout } = useAuth()
const { organization, organizations, hasMultipleOrganizations, switchOrganization } = useOrganization()
const { role } = usePermissions()
const loggingOut = ref(false)

async function onLogout() {
  loggingOut.value = true
  try {
    await logout()
    await navigateTo('/login')
  }
  finally {
    loggingOut.value = false
  }
}
</script>

<template>
  <main class="mx-auto max-w-lg px-6 py-16">
    <AppLogo />
    <UiCard
      class="mt-8"
      title="Sessão"
    >
      <dl class="space-y-3 text-sm">
        <div class="flex justify-between gap-4">
          <dt class="text-ink/55">
            Usuário
          </dt>
          <dd class="font-medium">
            {{ user?.name }} · {{ user?.email }}
          </dd>
        </div>
        <div class="flex justify-between gap-4">
          <dt class="text-ink/55">
            Organização
          </dt>
          <dd class="font-medium">
            {{ organization?.name }} ({{ role }})
          </dd>
        </div>
      </dl>
      <div
        v-if="hasMultipleOrganizations"
        class="mt-4 flex flex-wrap gap-2"
      >
        <UiButton
          v-for="item in organizations"
          :key="item.id"
          size="sm"
          :variant="item.id === organization?.id ? 'primary' : 'outline'"
          @click="switchOrganization(item.id)"
        >
          {{ item.name }}
        </UiButton>
      </div>
      <template #footer>
        <UiButton
          variant="outline"
          :loading="loggingOut"
          @click="onLogout"
        >
          Sair
        </UiButton>
      </template>
    </UiCard>
  </main>
</template>
