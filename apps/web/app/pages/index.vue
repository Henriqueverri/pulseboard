<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

const { user, organization, logout } = useAuth()
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
    <p class="text-sm font-medium tracking-wide text-slate-500 uppercase">
      PulseBoard
    </p>
    <h1 class="mt-2 text-3xl font-semibold tracking-tight">
      Signed in
    </h1>
    <p class="mt-2 text-slate-600">
      Auth foundation is ready. Domain screens arrive in later phases.
    </p>

    <dl class="mt-8 space-y-3 text-sm">
      <div class="flex justify-between gap-4 border-b border-slate-200 pb-3">
        <dt class="text-slate-500">
          User
        </dt>
        <dd class="font-medium">
          {{ user?.name }}
        </dd>
      </div>
      <div class="flex justify-between gap-4 border-b border-slate-200 pb-3">
        <dt class="text-slate-500">
          Email
        </dt>
        <dd class="font-medium">
          {{ user?.email }}
        </dd>
      </div>
      <div class="flex justify-between gap-4 border-b border-slate-200 pb-3">
        <dt class="text-slate-500">
          Organization
        </dt>
        <dd class="font-medium">
          {{ organization?.name }}
        </dd>
      </div>
      <div class="flex justify-between gap-4 border-b border-slate-200 pb-3">
        <dt class="text-slate-500">
          Role
        </dt>
        <dd class="font-medium">
          {{ organization?.role }}
        </dd>
      </div>
    </dl>

    <button
      type="button"
      class="mt-8 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900 disabled:opacity-60"
      :disabled="loggingOut"
      @click="onLogout"
    >
      {{ loggingOut ? 'Signing out…' : 'Sign out' }}
    </button>
  </main>
</template>
