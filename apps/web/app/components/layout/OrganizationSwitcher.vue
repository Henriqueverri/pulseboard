<script setup lang="ts">
import { PhBuildings, PhCaretUpDown } from '@phosphor-icons/vue'
import type { MenuItem } from '~/components/ui/DropdownMenu.vue'

withDefaults(defineProps<{
  /** `block` fills its container (navigation drawer). */
  block?: boolean
}>(), {
  block: false,
})

const { organization, organizations, hasMultipleOrganizations, switchOrganization } = useOrganization()

const items = computed<MenuItem[]>(() => organizations.value.map(item => ({
  label: item.name,
  checked: item.id === organization.value?.id,
  onSelect: () => switchOrganization(item.id),
})))
</script>

<template>
  <UiDropdownMenu
    v-if="hasMultipleOrganizations"
    :items="items"
    label="Organizações"
    align="end"
  >
    <button
      type="button"
      class="flex h-9 min-w-0 items-center gap-2 rounded-lg border border-ink/10 bg-surface px-3 text-sm font-medium text-ink hover:bg-ink/[0.03]"
      :class="block ? 'w-full' : 'max-w-[240px]'"
      aria-label="Trocar organização"
    >
      <PhBuildings
        :size="16"
        class="shrink-0 text-ink/50"
        aria-hidden="true"
      />
      <span class="min-w-0 flex-1 truncate text-left">{{ organization?.name }}</span>
      <PhCaretUpDown
        :size="14"
        class="shrink-0 text-ink/40"
        aria-hidden="true"
      />
    </button>
  </UiDropdownMenu>
  <div
    v-else-if="organization"
    class="flex h-9 min-w-0 items-center gap-2 px-1 text-sm font-medium text-ink/80"
    :class="block ? 'w-full' : 'max-w-[240px]'"
  >
    <PhBuildings
      :size="16"
      class="shrink-0 text-ink/45"
      aria-hidden="true"
    />
    <span class="truncate">{{ organization.name }}</span>
  </div>
</template>
