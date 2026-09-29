<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core'
import { isNavItemActive, NAVIGATION } from '~/utils/navigation'

const props = withDefaults(defineProps<{
  /** `responsive`: rail below 1280px and when collapsed; `expanded`: always with labels (drawer). */
  mode?: 'responsive' | 'expanded'
  collapsed?: boolean
}>(), {
  mode: 'responsive',
  collapsed: false,
})

const route = useRoute()
const isDesktop = useMediaQuery('(min-width: 1280px)')

/** Labels stay in the DOM as `sr-only` in the rail so every link keeps its accessible name. */
const labelClass = computed(() => {
  if (props.mode === 'expanded') {
    return ''
  }

  return props.collapsed ? 'sr-only' : 'sr-only xl:not-sr-only'
})

const showsRail = computed(() => props.mode === 'responsive' && (props.collapsed || !isDesktop.value))
</script>

<template>
  <nav
    aria-label="Navegação principal"
    class="flex flex-col gap-6"
  >
    <div
      v-for="group in NAVIGATION"
      :key="group.label"
    >
      <p class="mb-1.5 px-3 text-xs font-medium text-ink/40">
        <span :class="labelClass">{{ group.label }}</span>
      </p>
      <ul class="flex flex-col gap-0.5">
        <li
          v-for="item in group.items"
          :key="item.to"
        >
          <UiTooltip
            :content="item.label"
            side="right"
            :disabled="!showsRail"
          >
            <NuxtLink
              :to="item.to"
              class="group flex h-9 items-center gap-3 rounded-lg px-3 text-sm transition-colors"
              :class="[
                isNavItemActive(item, route.path)
                  ? 'bg-ink/[0.06] font-medium text-ink'
                  : 'text-ink/65 hover:bg-ink/[0.04] hover:text-ink',
                mode === 'responsive' && (collapsed ? 'justify-center px-0' : 'max-xl:justify-center max-xl:px-0'),
              ]"
              :aria-current="isNavItemActive(item, route.path) ? 'page' : undefined"
            >
              <component
                :is="item.icon"
                :size="18"
                :weight="isNavItemActive(item, route.path) ? 'fill' : 'regular'"
                class="shrink-0"
                aria-hidden="true"
              />
              <span :class="labelClass">{{ item.label }}</span>
            </NuxtLink>
          </UiTooltip>
        </li>
      </ul>
    </div>
  </nav>
</template>
