<script setup lang="ts">
import type { RankingItem } from '~/types/analytics'

defineProps<{
  items: RankingItem[]
  label: string
}>()
</script>

<template>
  <ol
    class="flex flex-col gap-3"
    :aria-label="label"
  >
    <li
      v-for="item in items"
      :key="item.key"
      class="flex items-start gap-3"
    >
      <span
        class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md bg-ink/[0.05] text-xs font-semibold text-ink/65 tabular"
        aria-hidden="true"
      >{{ item.rank }}</span>
      <div class="min-w-0 flex-1">
        <div class="flex items-baseline justify-between gap-3">
          <div class="flex min-w-0 items-center gap-2">
            <NuxtLink
              v-if="item.to && !item.removed"
              :to="item.to"
              class="truncate text-sm font-medium text-ink hover:text-brand-700 hover:underline"
            >
              <span class="sr-only">{{ item.rank }}º </span>{{ item.label }}
            </NuxtLink>
            <span
              v-else
              class="truncate text-sm font-medium text-ink/70"
            ><span class="sr-only">{{ item.rank }}º </span>{{ item.label }}</span>
            <UiTag
              v-if="item.removed"
              size="sm"
            >
              Removido
            </UiTag>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <span class="text-sm font-semibold text-ink tabular">{{ item.value }}</span>
            <ChangeIndicator
              v-if="item.change"
              :metric="item.change"
              size="sm"
              class="max-sm:hidden"
            />
          </div>
        </div>
        <p
          v-if="item.sublabel"
          class="truncate text-xs text-ink/65"
        >
          {{ item.sublabel }}
        </p>
        <div
          class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink/[0.05]"
          aria-hidden="true"
        >
          <div
            class="h-full rounded-full bg-brand-400"
            :style="{ width: `${Math.max(2, Math.min(1, item.share) * 100)}%` }"
          />
        </div>
      </div>
    </li>
  </ol>
</template>
