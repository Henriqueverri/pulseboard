<script setup lang="ts">
withDefaults(defineProps<{
  title?: string
  description?: string
  padding?: 'none' | 'sm' | 'md'
  as?: string
}>(), {
  title: undefined,
  description: undefined,
  padding: 'md',
  as: 'section',
})
</script>

<template>
  <component
    :is="as"
    class="flex min-w-0 flex-col rounded-xl border border-ink/[0.07] bg-surface"
  >
    <header
      v-if="title || $slots.header || $slots.actions"
      class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2"
      :class="padding === 'none' ? 'px-5 pt-5' : padding === 'sm' ? 'px-4 pt-4' : 'px-5 pt-5'"
    >
      <slot name="header">
        <div class="min-w-0">
          <h2 class="text-sm font-semibold text-ink">
            {{ title }}
          </h2>
          <p
            v-if="description"
            class="mt-0.5 text-xs text-ink/55"
          >
            {{ description }}
          </p>
        </div>
      </slot>
      <div
        v-if="$slots.actions"
        class="flex shrink-0 items-center gap-2"
      >
        <slot name="actions" />
      </div>
    </header>
    <div
      class="min-w-0 flex-1"
      :class="{ 'p-4': padding === 'sm', 'p-5': padding === 'md' }"
    >
      <slot />
    </div>
    <footer
      v-if="$slots.footer"
      class="border-t border-ink/[0.07] px-5 py-3"
    >
      <slot name="footer" />
    </footer>
  </component>
</template>
