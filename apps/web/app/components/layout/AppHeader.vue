<script setup lang="ts">
import { PhList } from '@phosphor-icons/vue'

const { openDrawer } = useSidebar()
</script>

<template>
  <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-ink/[0.07] bg-canvas/85 px-4 backdrop-blur-md sm:px-6 lg:px-8">
    <UiButton
      icon
      variant="ghost"
      class="-ml-2 md:hidden"
      aria-label="Abrir menu"
      @click="openDrawer"
    >
      <PhList :size="20" />
    </UiButton>
    <AppBreadcrumbs class="flex-1" />
    <div class="flex shrink-0 items-center gap-2">
      <OrganizationSwitcher class="max-md:hidden" />
      <UserMenu />
    </div>
  </header>
</template>
