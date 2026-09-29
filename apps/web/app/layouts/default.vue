<script setup lang="ts">
const { collapsed, drawerOpen, closeDrawer } = useSidebar()
const route = useRoute()

watch(() => route.fullPath, closeDrawer)
</script>

<template>
  <div class="min-h-screen">
    <a
      href="#main"
      class="sr-only z-50 rounded-lg bg-ink px-3 py-2 text-sm text-white focus:not-sr-only focus:fixed focus:left-3 focus:top-3"
    >Pular para o conteúdo</a>

    <AppSidebar />

    <div
      class="flex min-h-screen min-w-0 flex-col transition-[padding] duration-200 md:pl-rail"
      :class="collapsed ? '' : 'xl:pl-sidebar'"
    >
      <AppHeader />
      <main
        id="main"
        tabindex="-1"
        class="mx-auto w-full max-w-[1440px] flex-1 px-4 py-6 outline-none sm:px-6 lg:px-8 lg:py-8"
      >
        <slot />
      </main>
    </div>

    <UiDrawer
      v-model:open="drawerOpen"
      side="left"
      title="Menu"
      hide-header
    >
      <div class="flex h-full flex-col">
        <div class="flex h-16 shrink-0 items-center px-5">
          <AppLogo />
        </div>
        <div class="flex-1 px-3 py-2">
          <AppNav mode="expanded" />
        </div>
        <div class="border-t border-ink/[0.07] p-3 md:hidden">
          <OrganizationSwitcher block />
        </div>
      </div>
    </UiDrawer>
  </div>
</template>
