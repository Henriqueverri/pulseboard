<script setup lang="ts">
import { PhSidebarSimple } from '@phosphor-icons/vue'
import { useMediaQuery } from '@vueuse/core'

const { collapsed, toggleCollapsed, openDrawer } = useSidebar()
const isDesktop = useMediaQuery('(min-width: 1280px)')

const toggleLabel = computed(() => {
  if (!isDesktop.value) {
    return 'Expandir menu'
  }

  return collapsed.value ? 'Expandir menu' : 'Recolher menu'
})

function onToggle() {
  if (isDesktop.value) {
    toggleCollapsed()
  }
  else {
    openDrawer()
  }
}
</script>

<template>
  <aside
    class="fixed inset-y-0 left-0 z-30 hidden flex-col border-r border-ink/[0.07] bg-surface transition-[width] duration-200 md:flex"
    :class="collapsed ? 'w-rail' : 'w-rail xl:w-sidebar'"
  >
    <div
      class="flex h-16 shrink-0 items-center px-4"
      :class="collapsed ? 'justify-center' : 'max-xl:justify-center'"
    >
      <NuxtLink
        to="/dashboard"
        class="rounded-lg"
        aria-label="PulseBoard — Dashboard"
      >
        <AppLogo
          v-if="!collapsed"
          class="hidden xl:inline-flex"
        />
        <AppLogo
          variant="mark"
          :class="collapsed ? '' : 'xl:hidden'"
        />
      </NuxtLink>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-3 py-4">
      <AppNav :collapsed="collapsed" />
    </div>

    <div
      class="flex shrink-0 border-t border-ink/[0.07] p-3"
      :class="collapsed ? 'justify-center' : 'max-xl:justify-center'"
    >
      <UiTooltip
        :content="toggleLabel"
        side="right"
      >
        <UiButton
          icon
          variant="ghost"
          size="sm"
          :aria-label="toggleLabel"
          :aria-expanded="isDesktop ? !collapsed : undefined"
          @click="onToggle"
        >
          <PhSidebarSimple :size="18" />
        </UiButton>
      </UiTooltip>
    </div>
  </aside>
</template>
