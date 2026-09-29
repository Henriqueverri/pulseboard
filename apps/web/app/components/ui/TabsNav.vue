<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

export interface TabLink {
  label: string
  to: RouteLocationRaw
  /** Path prefix used to mark the tab active. */
  match: string
}

defineProps<{ tabs: TabLink[], label: string }>()

const route = useRoute()
</script>

<template>
  <nav
    :aria-label="label"
    class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0"
  >
    <ul class="flex min-w-max gap-1 border-b border-ink/[0.08]">
      <li
        v-for="tab in tabs"
        :key="tab.match"
      >
        <NuxtLink
          :to="tab.to"
          class="relative -mb-px inline-flex h-10 items-center border-b-2 px-3 text-sm font-medium transition-colors"
          :class="route.path.startsWith(tab.match)
            ? 'border-ink text-ink'
            : 'border-transparent text-ink/65 hover:text-ink'"
          :aria-current="route.path.startsWith(tab.match) ? 'page' : undefined"
        >
          {{ tab.label }}
        </NuxtLink>
      </li>
    </ul>
  </nav>
</template>
