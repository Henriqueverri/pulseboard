<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{ error: NuxtError }>()

const isNotFound = computed(() => props.error.statusCode === 404)

const title = computed(() => {
  if (isNotFound.value) {
    return 'Página não encontrada'
  }

  return props.error.statusCode === 503 ? 'Serviço indisponível' : 'Algo deu errado'
})

const description = computed(() => {
  if (isNotFound.value) {
    return 'O endereço pode ter mudado ou o registro não existe mais.'
  }

  // Router/runtime errors carry technical English messages; API-driven ones are already pt-BR.
  return props.error.statusCode === 503 && props.error.statusMessage
    ? props.error.statusMessage
    : 'Ocorreu um erro inesperado. Tente novamente em instantes.'
})

useHead({ title: `${title.value} · PulseBoard` })

function retry() {
  window.location.reload()
}

function goHome() {
  clearError({ redirect: '/dashboard' })
}
</script>

<template>
  <main class="flex min-h-screen flex-col items-center justify-center bg-canvas px-6 py-16 text-center">
    <AppLogo />
    <p class="mt-12 text-sm font-semibold text-brand-600 tabular">
      {{ error.statusCode }}
    </p>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-ink sm:text-3xl">
      {{ title }}
    </h1>
    <p class="mt-3 max-w-md text-sm text-ink/60">
      {{ description }}
    </p>
    <div class="mt-8 flex flex-wrap justify-center gap-2">
      <UiButton
        v-if="!isNotFound"
        variant="outline"
        @click="retry"
      >
        Tentar novamente
      </UiButton>
      <UiButton
        variant="primary"
        @click="goHome"
      >
        Ir para o dashboard
      </UiButton>
    </div>
  </main>
</template>
