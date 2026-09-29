<script setup lang="ts">
import { HOME_PATH } from '~/utils/auth-redirect'

definePageMeta({
  layout: 'auth',
  auth: 'guest',
})

useHead({ title: 'Criar conta · PulseBoard' })

const auth = useAuth()

const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })
const pending = ref(false)
const { fieldErrors, formError, capture, clear, reset } = useFormErrors(
  ['name', 'email', 'password', 'password_confirmation'] as const,
)

async function onSubmit() {
  reset()
  pending.value = true

  try {
    await auth.register({
      name: form.name.trim(),
      email: form.email.trim(),
      password: form.password,
      password_confirmation: form.password_confirmation,
    })
    await navigateTo(HOME_PATH)
  }
  catch (error) {
    capture(error, 'Não foi possível criar a conta. Tente novamente.')
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-semibold tracking-tight">
      Criar conta
    </h1>
    <p class="mt-1.5 text-sm text-ink/55">
      Uma organização é criada para você, com você como owner.
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
        label="Nome"
        :error="fieldErrors.name"
      >
        <UiInput
          :id="id"
          v-model="form.name"
          name="name"
          autocomplete="name"
          size="lg"
          maxlength="255"
          required
          autofocus
          :invalid="invalid"
          :described-by="describedBy"
          @update:model-value="clear('name')"
        />
      </UiFormField>

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
          maxlength="255"
          required
          :invalid="invalid"
          :described-by="describedBy"
          @update:model-value="clear('email')"
        />
      </UiFormField>

      <UiFormField
        v-slot="{ id, describedBy, invalid }"
        label="Senha"
        hint="Mínimo de 8 caracteres."
        :error="fieldErrors.password"
      >
        <PasswordInput
          :id="id"
          v-model="form.password"
          name="password"
          autocomplete="new-password"
          required
          :invalid="invalid"
          :described-by="describedBy"
          @update:model-value="clear('password')"
        />
      </UiFormField>

      <UiFormField
        v-slot="{ id, describedBy, invalid }"
        label="Confirme a senha"
        :error="fieldErrors.password_confirmation"
      >
        <PasswordInput
          :id="id"
          v-model="form.password_confirmation"
          name="password_confirmation"
          autocomplete="new-password"
          required
          :invalid="invalid"
          :described-by="describedBy"
          @update:model-value="clear('password_confirmation')"
        />
      </UiFormField>

      <UiButton
        type="submit"
        variant="primary"
        size="lg"
        block
        :loading="pending"
      >
        Criar conta
      </UiButton>
    </form>

    <p class="mt-8 text-center text-sm text-ink/55">
      Já tem conta?
      <NuxtLink
        to="/login"
        class="font-medium text-ink underline-offset-4 hover:underline"
      >
        Entrar
      </NuxtLink>
    </p>
  </div>
</template>
