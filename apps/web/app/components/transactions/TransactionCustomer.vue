<script setup lang="ts">
import type { TransactionCustomer } from '~/types/transaction'

defineProps<{
  customer: TransactionCustomer
  showEmail?: boolean
}>()
</script>

<template>
  <div class="min-w-0">
    <div class="flex min-w-0 items-center gap-2">
      <span
        v-if="customer.is_deleted"
        class="truncate font-medium text-ink/70"
      >{{ customer.name }}</span>
      <NuxtLink
        v-else
        :to="`/customers/${customer.id}`"
        class="truncate font-medium text-ink hover:underline"
      >
        {{ customer.name }}
      </NuxtLink>
      <UiTag
        v-if="customer.is_deleted"
        size="sm"
      >
        Removido
      </UiTag>
    </div>
    <p
      v-if="showEmail"
      class="truncate text-xs text-ink/65"
    >
      {{ customer.email }}
    </p>
  </div>
</template>
