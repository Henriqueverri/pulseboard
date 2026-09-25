<script setup lang="ts">
import { ApiError } from '~/types/auth'

definePageMeta({
  layout: 'auth',
  middleware: 'guest',
})

const auth = useAuth()

const email = ref('')
const password = ref('')
const pending = ref(false)
const formError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

async function onSubmit() {
  formError.value = null
  fieldErrors.value = {}
  pending.value = true

  try {
    await auth.login({
      email: email.value,
      password: password.value,
    })
    await navigateTo('/')
  }
  catch (error) {
    if (error instanceof ApiError) {
      formError.value = error.message
      fieldErrors.value = error.body?.errors ?? {}
    }
    else {
      formError.value = 'Unable to sign in. Try again.'
    }
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-3xl font-semibold tracking-tight">
      Sign in
    </h1>
    <p class="mt-2 text-slate-600">
      Access your organization with a secure session cookie.
    </p>

    <form
      class="mt-8 space-y-4"
      @submit.prevent="onSubmit"
    >
      <div>
        <label
          for="email"
          class="block text-sm font-medium text-slate-700"
        >Email</label>
        <input
          id="email"
          v-model="email"
          type="email"
          required
          autocomplete="email"
          class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-500"
        >
        <p
          v-if="fieldErrors.email"
          class="mt-1 text-sm text-red-600"
        >
          {{ fieldErrors.email[0] }}
        </p>
      </div>

      <div>
        <label
          for="password"
          class="block text-sm font-medium text-slate-700"
        >Password</label>
        <input
          id="password"
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-slate-500"
        >
        <p
          v-if="fieldErrors.password"
          class="mt-1 text-sm text-red-600"
        >
          {{ fieldErrors.password[0] }}
        </p>
      </div>

      <p
        v-if="formError"
        class="text-sm text-red-600"
      >
        {{ formError }}
      </p>

      <button
        type="submit"
        class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
        :disabled="pending"
      >
        {{ pending ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>

    <p class="mt-6 text-sm text-slate-600">
      No account?
      <NuxtLink
        to="/register"
        class="font-medium text-slate-900 underline"
      >
        Create one
      </NuxtLink>
    </p>
  </div>
</template>
