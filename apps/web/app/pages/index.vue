<script setup lang="ts">
const config = useRuntimeConfig()

const { data: health, error, pending } = await useFetch<{
  status: string
  app: string
  database: string
}>(`${config.public.apiUrl}/health`, {
  server: false,
})
</script>

<template>
  <main class="min-h-screen bg-slate-50 text-slate-900">
    <div class="mx-auto flex max-w-lg flex-col gap-6 px-6 py-16">
      <div>
        <p class="text-sm font-medium tracking-wide text-slate-500 uppercase">
          Foundation
        </p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">
          PulseBoard
        </h1>
        <p class="mt-2 text-slate-600">
          Monorepo scaffold — Nuxt 4 + Laravel 12 + PostgreSQL.
        </p>
      </div>

      <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-sm font-medium text-slate-700">
          API health
        </p>
        <p
          v-if="pending"
          class="mt-2 text-sm text-slate-500"
        >
          Checking…
        </p>
        <p
          v-else-if="error"
          class="mt-2 text-sm text-red-600"
        >
          API unreachable at {{ config.public.apiUrl }}/health
        </p>
        <dl
          v-else-if="health"
          class="mt-3 grid grid-cols-2 gap-2 text-sm"
        >
          <dt class="text-slate-500">
            Status
          </dt>
          <dd class="font-medium">
            {{ health.status }}
          </dd>
          <dt class="text-slate-500">
            App
          </dt>
          <dd class="font-medium">
            {{ health.app }}
          </dd>
          <dt class="text-slate-500">
            Database
          </dt>
          <dd class="font-medium">
            {{ health.database }}
          </dd>
        </dl>
      </div>
    </div>
  </main>
</template>
