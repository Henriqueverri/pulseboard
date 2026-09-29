<script setup lang="ts">
import { PhChartLineUp, PhShieldCheck, PhUsersThree } from '@phosphor-icons/vue'

const highlights = [
  { icon: PhChartLineUp, title: 'Receita e pedidos', text: 'Compare cada período com o anterior, no fuso da sua organização.' },
  { icon: PhUsersThree, title: 'Produtos e clientes', text: 'Rankings de quem mais vende e de quem mais compra.' },
  { icon: PhShieldCheck, title: 'Dados isolados', text: 'Cada organização vê somente as próprias transações.' },
]
</script>

<template>
  <div class="flex min-h-screen bg-surface">
    <aside class="relative hidden w-[44%] max-w-[640px] flex-col justify-between overflow-hidden bg-ink p-10 text-white lg:flex xl:p-14">
      <div
        class="pointer-events-none absolute -right-32 -top-32 size-[420px] rounded-full bg-brand-500/30 blur-[120px]"
        aria-hidden="true"
      />
      <div
        class="pointer-events-none absolute -bottom-40 -left-20 size-[360px] rounded-full bg-purple/20 blur-[120px]"
        aria-hidden="true"
      />

      <AppLogo
        inverted
        class="relative"
      />

      <div class="relative max-w-md">
        <h2 class="text-3xl font-semibold leading-tight tracking-tight xl:text-4xl">
          As métricas de vendas da sua organização, em um só lugar.
        </h2>
        <ul class="mt-10 space-y-6">
          <li
            v-for="item in highlights"
            :key="item.title"
            class="flex gap-4"
          >
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/10">
              <component
                :is="item.icon"
                :size="18"
                aria-hidden="true"
              />
            </span>
            <span>
              <span class="block text-sm font-medium">{{ item.title }}</span>
              <span class="mt-0.5 block text-sm text-white/60">{{ item.text }}</span>
            </span>
          </li>
        </ul>
      </div>

      <p class="relative text-xs text-white/65">
        © {{ new Date().getFullYear() }} PulseBoard
      </p>
    </aside>

    <main class="flex flex-1 flex-col px-6 py-8 sm:px-10">
      <AppLogo class="lg:hidden" />
      <div class="flex flex-1 items-center justify-center py-10">
        <div class="w-full max-w-sm">
          <slot />
        </div>
      </div>
    </main>
  </div>
</template>
