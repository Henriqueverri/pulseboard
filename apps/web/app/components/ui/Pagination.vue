<script setup lang="ts">
import { PhCaretLeft, PhCaretRight } from '@phosphor-icons/vue'
import type { PaginationMeta } from '~/types/api'

const props = withDefaults(defineProps<{
  meta: Pick<PaginationMeta, 'current_page' | 'last_page' | 'total' | 'from' | 'to'>
  disabled?: boolean
  /** Shows the page size selector when set. */
  perPage?: number
  perPageOptions?: readonly number[]
}>(), {
  disabled: false,
  perPage: undefined,
  perPageOptions: () => [15, 25, 50],
})

const emit = defineEmits<{ 'update:page': [page: number], 'update:perPage': [perPage: number] }>()

const perPageModel = computed({
  get: () => String(props.perPage),
  set: (value: string) => emit('update:perPage', Number(value)),
})

const perPageSelectOptions = computed(() => {
  const values = new Set([...props.perPageOptions, ...(props.perPage ? [props.perPage] : [])])

  return [...values].sort((a, b) => a - b).map(value => ({ value: String(value), label: String(value) }))
})

type PageItem = number | 'gap'

/** Compact page list: 1 … 4 5 6 … 20 */
const pages = computed<PageItem[]>(() => {
  const current = props.meta.current_page
  const last = props.meta.last_page

  if (last <= 7) {
    return Array.from({ length: last }, (_, index) => index + 1)
  }

  const items: PageItem[] = [1]
  const start = Math.max(2, current - 1)
  const end = Math.min(last - 1, current + 1)

  if (start > 2) {
    items.push('gap')
  }
  for (let page = start; page <= end; page++) {
    items.push(page)
  }
  if (end < last - 1) {
    items.push('gap')
  }
  items.push(last)

  return items
})

function go(page: number) {
  if (!props.disabled && page >= 1 && page <= props.meta.last_page && page !== props.meta.current_page) {
    emit('update:page', page)
  }
}
</script>

<template>
  <nav
    class="flex flex-wrap items-center justify-between gap-3"
    aria-label="Paginação"
  >
    <div class="flex items-center gap-4">
      <p class="text-xs text-ink/55 tabular">
        <template v-if="meta.total > 0">
          Mostrando <span class="font-medium text-ink">{{ formatInteger(meta.from) }}–{{ formatInteger(meta.to) }}</span>
          de <span class="font-medium text-ink">{{ formatInteger(meta.total) }}</span>
        </template>
        <template v-else>
          Nenhum resultado
        </template>
      </p>
      <div
        v-if="perPage"
        class="hidden items-center gap-2 whitespace-nowrap text-xs text-ink/55 sm:flex"
      >
        <span aria-hidden="true">Por página</span>
        <UiSelect
          v-model="perPageModel"
          :options="perPageSelectOptions"
          label="Itens por página"
          size="sm"
          :disabled="disabled"
          class="w-[72px]"
        />
      </div>
    </div>
    <div
      v-if="meta.last_page > 1"
      class="flex items-center gap-1"
    >
      <UiButton
        size="sm"
        variant="ghost"
        icon
        aria-label="Página anterior"
        :disabled="disabled || meta.current_page <= 1"
        @click="go(meta.current_page - 1)"
      >
        <PhCaretLeft :size="16" />
      </UiButton>
      <span class="px-2 text-xs text-ink/60 tabular sm:hidden">
        {{ meta.current_page }} / {{ meta.last_page }}
      </span>
      <template
        v-for="(item, index) in pages"
        :key="`${item}-${index}`"
      >
        <span
          v-if="item === 'gap'"
          class="hidden w-6 text-center text-xs text-ink/40 sm:inline"
          aria-hidden="true"
        >…</span>
        <button
          v-else
          type="button"
          class="hidden h-8 min-w-8 items-center justify-center rounded-md px-2 text-xs font-medium tabular transition-colors sm:inline-flex"
          :class="item === meta.current_page ? 'bg-ink text-white' : 'text-ink/70 hover:bg-ink/[0.05]'"
          :aria-current="item === meta.current_page ? 'page' : undefined"
          :aria-label="`Página ${item}`"
          :disabled="disabled"
          @click="go(item)"
        >
          {{ item }}
        </button>
      </template>
      <UiButton
        size="sm"
        variant="ghost"
        icon
        aria-label="Próxima página"
        :disabled="disabled || meta.current_page >= meta.last_page"
        @click="go(meta.current_page + 1)"
      >
        <PhCaretRight :size="16" />
      </UiButton>
    </div>
  </nav>
</template>
