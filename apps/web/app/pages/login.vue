<script setup lang="ts">
import { HOME_PATH, safeRedirect } from '~/utils/auth-redirect'

definePageMeta({
  layout: 'auth',
  auth: 'guest',
})

useHead({ title: 'Entrar · PulseBoard' })

const auth = useAuth()
const route = useRoute()

const form = reactive({ email: '', password: '' })
const pending = ref(false)
const { fieldErrors, formError, capture, clear, reset } = useFormErrors(['email', 'password'] as const)

async function onSubmit() {
  reset()
  pending.value = true

  try {
    await auth.login({ email: form.email.trim(), password: form.password })
    await navigateTo(safeRedirect(route.query.redirect) ?? HOME_PATH)
  }
  catch (error) {
    capture(error, 'Não foi possível entrar. Tente novamente.')
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold tracking-tight">
      Entrar
    </h1>
    <p class="mt-1.5 text-sm text-ink/65">
      Acesse o painel da sua organização.
    </p>

    <form
      class="mt-8 space-y-5"
      novalidate
      @submit.prevent="onSubmit"
    >
      <UiAlert
        v-if="formError"
        tone="danger"
      >
        {{ formError }}
      </UiAlert>

      <UiFormField
        v-slot="{ id, describedBy, invalid }"
        label="E-mail"
        :error="fieldErrors.email"
      >
        <UiInput
          :id="id"
          v-model="form.email"
          type="email"
          name="email"
          autocomplete="email"
          inputmode="email"
          size="lg"
          required
          autofocus
          :invalid="invalid"
          :described-by="describedBy"
          @update:model-value="clear('email')"
        />
      </UiFormField>

      <UiFormField
        v-slot="{ id, describedBy, invalid }"
        label="Senha"
        :error="fieldErrors.password"
      >
        <PasswordInput
          :id="id"
          v-model="form.password"
          name="password"
          autocomplete="current-password"
          required
          :invalid="invalid"
          :described-by="describedBy"
          @update:model-value="clear('password')"
        />
      </UiFormField>

      <UiButton
        type="submit"
        variant="primary"
        size="lg"
        block
        :loading="pending"
      >
        Entrar
      </UiButton>
    </form>

    <p class="mt-8 text-center text-sm text-ink/65">
      Ainda não tem conta?
      <NuxtLink
        to="/register"
        class="font-medium text-ink underline-offset-4 hover:underline"
      >
        Criar conta
      </NuxtLink>
    </p>
  </div>
</template>
