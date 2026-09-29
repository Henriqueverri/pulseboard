<script setup lang="ts">
import { PhCaretRight } from '@phosphor-icons/vue'

const { crumbs } = useBreadcrumbs()
</script>

<template>
  <nav
    aria-label="Trilha de navegação"
    class="min-w-0"
  >
    <ol class="flex min-w-0 items-center gap-1.5 text-sm">
      <li
        v-for="(crumb, index) in crumbs"
        :key="`${crumb.label}-${index}`"
        class="flex min-w-0 items-center gap-1.5"
        :class="index < crumbs.length - 1 ? 'max-md:hidden' : ''"
      >
        <NuxtLink
          v-if="crumb.to"
          :to="crumb.to"
          class="truncate text-ink/50 hover:text-ink"
        >
          {{ crumb.label }}
        </NuxtLink>
        <span
          v-else
          class="truncate font-medium text-ink"
          aria-current="page"
        >
          {{ crumb.label }}
        </span>
        <PhCaretRight
          v-if="index < crumbs.length - 1"
          :size="12"
          class="shrink-0 text-ink/30"
          aria-hidden="true"
        />
      </li>
    </ol>
  </nav>
</template>
