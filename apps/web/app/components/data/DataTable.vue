<script setup lang="ts" generic="T">
export interface DataColumn {
  key: string
  label: string
  align?: 'left' | 'right'
  /** Hide the column on narrow screens (the row should repeat the essentials elsewhere). */
  hideBelow?: 'sm' | 'md' | 'lg'
  /** Keep the header only for screen readers (e.g. an actions column). */
  srOnlyLabel?: boolean
  class?: string
}

const props = withDefaults(defineProps<{
  columns: DataColumn[]
  rows: T[]
  rowKey: (row: T) => string
  caption: string
  loading?: boolean
  busy?: boolean
  skeletonRows?: number
}>(), {
  loading: false,
  busy: false,
  skeletonRows: 8,
})

defineSlots<{
  [key: `cell-${string}`]: (props: { row: T }) => unknown
  empty?: () => unknown
}>()

const HIDE_BELOW = {
  sm: 'hidden sm:table-cell',
  md: 'hidden md:table-cell',
  lg: 'hidden lg:table-cell',
} as const

function cellClass(column: DataColumn) {
  return [
    column.align === 'right' ? 'text-right' : 'text-left',
    column.hideBelow ? HIDE_BELOW[column.hideBelow] : '',
    column.class,
  ]
}

function valueOf(row: T, key: string): unknown {
  return (row as Record<string, unknown>)[key]
}

const showEmpty = computed(() => !props.loading && props.rows.length === 0)
</script>

<template>
  <div
    class="relative overflow-x-auto"
    :aria-busy="loading || busy || undefined"
  >
    <table
      v-if="!showEmpty"
      class="w-full border-collapse text-sm"
    >
      <caption class="sr-only">
        {{ caption }}
      </caption>
      <thead>
        <tr class="border-b border-ink/[0.07]">
          <th
            v-for="column in columns"
            :key="column.key"
            scope="col"
            class="h-10 whitespace-nowrap px-4 text-xs font-medium text-ink/50 first:pl-5 last:pr-5"
            :class="cellClass(column)"
          >
            <span :class="column.srOnlyLabel ? 'sr-only' : ''">{{ column.label }}</span>
          </th>
        </tr>
      </thead>
      <tbody
        v-if="loading"
        aria-hidden="true"
      >
        <tr
          v-for="index in skeletonRows"
          :key="index"
          class="border-b border-ink/[0.05] last:border-0"
        >
          <td
            v-for="column in columns"
            :key="column.key"
            class="h-14 px-4 first:pl-5 last:pr-5"
            :class="cellClass(column)"
          >
            <UiSkeleton
              class="h-3.5"
              :class="[column.align === 'right' ? 'ml-auto w-16' : 'w-3/4 max-w-[180px]']"
            />
          </td>
        </tr>
      </tbody>
      <tbody
        v-else
        class="transition-opacity"
        :class="busy ? 'opacity-60' : ''"
      >
        <tr
          v-for="row in rows"
          :key="rowKey(row)"
          class="border-b border-ink/[0.05] transition-colors last:border-0 hover:bg-ink/[0.015]"
        >
          <td
            v-for="column in columns"
            :key="column.key"
            class="h-14 px-4 first:pl-5 last:pr-5"
            :class="cellClass(column)"
          >
            <slot
              :name="`cell-${column.key}`"
              :row="row"
            >
              {{ valueOf(row, column.key) ?? EMPTY_VALUE }}
            </slot>
          </td>
        </tr>
      </tbody>
    </table>
    <slot
      v-else
      name="empty"
    />
  </div>
</template>
