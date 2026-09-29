<script setup lang="ts">
import { PhCheck } from '@phosphor-icons/vue'
import type { Component } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from 'reka-ui'

export interface MenuItem {
  label: string
  icon?: Component
  to?: RouteLocationRaw
  danger?: boolean
  disabled?: boolean
  onSelect?: () => void
  /** Renders a separator before this item. */
  separated?: boolean
  /** Marks the current choice in single-choice menus. */
  checked?: boolean
}

withDefaults(defineProps<{
  items: MenuItem[]
  align?: 'start' | 'center' | 'end'
  side?: 'top' | 'right' | 'bottom' | 'left'
  label?: string
  contentClass?: string
}>(), {
  align: 'end',
  side: 'bottom',
  label: undefined,
  contentClass: undefined,
})

const NuxtLink = resolveComponent('NuxtLink')
</script>

<template>
  <DropdownMenuRoot :modal="false">
    <DropdownMenuTrigger as-child>
      <slot />
    </DropdownMenuTrigger>
    <DropdownMenuPortal>
      <DropdownMenuContent
        :align="align"
        :side="side"
        :side-offset="6"
        class="z-50 min-w-[180px] rounded-lg bg-surface p-1 shadow-pop data-[state=open]:animate-fade-in"
        :class="contentClass"
      >
        <slot name="header">
          <DropdownMenuLabel
            v-if="label"
            class="px-2 py-1.5 text-xs font-medium text-ink/65"
          >
            {{ label }}
          </DropdownMenuLabel>
        </slot>
        <template
          v-for="item in items"
          :key="item.label"
        >
          <DropdownMenuSeparator
            v-if="item.separated"
            class="my-1 h-px bg-ink/[0.07]"
          />
          <DropdownMenuItem
            :as-child="Boolean(item.to)"
            :disabled="item.disabled"
            class="flex h-8 cursor-pointer select-none items-center gap-2 rounded-md px-2 text-sm outline-none data-[disabled]:pointer-events-none data-[disabled]:opacity-40 data-[highlighted]:bg-ink/[0.05]"
            :class="item.danger ? 'text-danger-strong data-[highlighted]:bg-danger-soft' : 'text-ink'"
            @select="item.onSelect?.()"
          >
            <component
              :is="NuxtLink"
              v-if="item.to"
              :to="item.to"
            >
              <component
                :is="item.icon"
                v-if="item.icon"
                :size="16"
                aria-hidden="true"
              />
              {{ item.label }}
            </component>
            <template v-else>
              <component
                :is="item.icon"
                v-if="item.icon"
                :size="16"
                aria-hidden="true"
              />
              <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
              <PhCheck
                v-if="item.checked"
                :size="16"
                class="text-brand-600"
                aria-label="Selecionada"
              />
            </template>
          </DropdownMenuItem>
        </template>
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>
