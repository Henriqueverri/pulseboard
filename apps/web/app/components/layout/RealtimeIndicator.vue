<script setup lang="ts">
const status = useRealtimeStatus()

const live = computed(() => status.value === 'live')
const label = computed(() => ({ off: '', connecting: 'Conectando', live: 'Ao vivo', offline: 'Reconectando' })[status.value])
</script>

<template>
  <span
    v-if="label"
    role="status"
    class="inline-flex h-6 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2 text-xs font-medium"
    :class="live ? 'bg-success-soft text-success-strong' : 'bg-ink/[0.05] text-ink/70'"
    :title="live ? 'Vendas e estoque atualizam sozinhos' : 'Conectando às atualizações ao vivo'"
  >
    <span
      class="relative flex size-1.5"
      aria-hidden="true"
    >
      <span
        v-if="live"
        class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60 motion-reduce:hidden"
      />
      <span
        class="relative inline-flex size-1.5 rounded-full"
        :class="live ? 'bg-success' : 'bg-ink/40'"
      />
    </span>
    {{ label }}
  </span>
</template>
