<script setup lang="ts">
/**
 * Card for one independently loaded section: skeleton on first load, dimmed content while
 * refetching, error with retry, and an empty state. Content renders only with data.
 */
withDefaults(defineProps<{
  title: string
  description?: string
  loading?: boolean
  refreshing?: boolean
  error?: unknown
  empty?: boolean
  emptyTitle?: string
  emptyDescription?: string
}>(), {
  description: undefined,
  loading: false,
  refreshing: false,
  error: undefined,
  empty: false,
  emptyTitle: 'Nada para mostrar',
  emptyDescription: undefined,
})

const emit = defineEmits<{ retry: [] }>()
</script>

<template>
  <UiCard
    :title="title"
    :description="description"
  >
    <template
      v-if="$slots.actions"
      #actions
    >
      <slot name="actions" />
    </template>

    <UiErrorState
      v-if="error"
      compact
      :message="errorMessage(error)"
      :retrying="refreshing"
      @retry="emit('retry')"
    />
    <div
      v-else-if="loading"
      aria-busy="true"
    >
      <span class="sr-only">Carregando {{ title }}</span>
      <slot name="skeleton">
        <UiSkeleton class="h-48 w-full rounded-lg" />
      </slot>
    </div>
    <UiEmptyState
      v-else-if="empty"
      compact
      :title="emptyTitle"
      :description="emptyDescription"
    />
    <div
      v-else
      class="transition-opacity"
      :class="{ 'opacity-60': refreshing }"
      :aria-busy="refreshing || undefined"
    >
      <slot />
    </div>

    <template
      v-if="$slots.footer"
      #footer
    >
      <slot name="footer" />
    </template>
  </UiCard>
</template>
