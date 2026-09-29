<script setup lang="ts">
import { PhSignOut } from '@phosphor-icons/vue'
import type { MenuItem } from '~/components/ui/DropdownMenu.vue'
import { errorMessage } from '~/utils/api-error'

const { user, logout } = useAuth()
const { role } = usePermissions()
const toast = useToast()

const roleLabel = computed(() => role.value === 'owner' ? 'Owner' : role.value === 'member' ? 'Membro' : null)

async function onLogout() {
  try {
    await logout()
  }
  catch (error) {
    // The local session is cleared anyway; tell the user the server session may still be open.
    toast.error('Saída incompleta', errorMessage(error))
  }
  clearNuxtData()
  await navigateTo('/login')
}

const items: MenuItem[] = [
  { label: 'Sair', icon: PhSignOut, onSelect: onLogout },
]
</script>

<template>
  <UiDropdownMenu
    :items="items"
    align="end"
    content-class="w-64"
  >
    <button
      type="button"
      class="flex items-center rounded-full outline-offset-2"
      :aria-label="`Menu do usuário ${user?.name ?? ''}`.trim()"
    >
      <UiAvatar
        :name="user?.name ?? '?'"
        size="md"
      />
    </button>
    <template #header>
      <div class="flex items-center gap-3 px-2 py-2">
        <UiAvatar :name="user?.name ?? '?'" />
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium text-ink">
            {{ user?.name }}
          </p>
          <p class="truncate text-xs text-ink/55">
            {{ user?.email }}
          </p>
        </div>
        <UiTag
          v-if="roleLabel"
          size="sm"
          :tone="role === 'owner' ? 'brand' : 'neutral'"
        >
          {{ roleLabel }}
        </UiTag>
      </div>
      <div class="my-1 h-px bg-ink/[0.07]" />
    </template>
  </UiDropdownMenu>
</template>
