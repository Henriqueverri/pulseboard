<script setup lang="ts">
import { PhFingerprint, PhKey, PhListMagnifyingGlass } from '@phosphor-icons/vue'

const config = useRuntimeConfig()
const { currency } = useOrganization()
const toast = useToast()

const endpoint = computed(() => `${config.public.apiUrl}/ingest/transactions`)

/** Placeholders only: the key comes from an environment variable, never from this page. */
const curlExample = computed(() => [
  `curl -X POST ${endpoint.value} \\`,
  '  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \\',
  '  -H "Content-Type: application/json" \\',
  '  -H "Accept: application/json" \\',
  `  -d '${JSON.stringify({
    external_id: 'order_1001',
    status: 'paid',
    occurred_at: '2026-10-01T14:30:00-03:00',
    currency: currency.value,
    customer: { external_id: 'cus_42', name: 'Ana Lima', email: 'ana@example.com' },
    items: [{ sku: 'SKU-DO-PRODUTO', quantity: 2, unit_price: '49.90' }],
  }, null, 2).replace(/\n/g, '\n  ')}'`,
].join('\n'))

const RESPONSES: Array<{ status: number, tone: 'success' | 'warning' | 'danger' | 'neutral', title: string, detail: string }> = [
  { status: 201, tone: 'success', title: 'Criada', detail: 'Transação registrada; o header Location aponta para ela.' },
  { status: 200, tone: 'success', title: 'Reenvio idêntico', detail: 'Mesmo external_id e mesmo payload: devolve a transação existente, sem duplicar.' },
  { status: 409, tone: 'warning', title: 'Conflito', detail: 'Mesmo external_id com outro payload (transaction_conflict) ou e-mail de cliente já usado (customer_email_conflict).' },
  { status: 422, tone: 'warning', title: 'Payload inválido', detail: 'Erros por campo em errors: SKU inexistente, total ou moeda divergentes (validation_failed, total_mismatch, currency_mismatch).' },
  { status: 401, tone: 'danger', title: 'Chave inválida', detail: 'Ausente, malformada, revogada ou expirada (invalid_api_key).' },
  { status: 429, tone: 'neutral', title: 'Limite de requisições', detail: 'Limite por chave; aguarde o tempo do header Retry-After (rate_limited).' },
]

const FOLLOW_UP = [
  { method: 'GET', path: '/ingest/transactions/{external_id}', detail: 'Reconciliação: confirma se uma transação foi processada (por exemplo, após um timeout).' },
  { method: 'POST', path: '/ingest/transactions/{external_id}/status-changes', detail: 'Muda o status com { status, occurred_at }; transições inválidas recebem 409 (invalid_transition).' },
]

function copyFailed() {
  toast.error('Não foi possível copiar. Selecione o texto e copie manualmente.')
}
</script>

<template>
  <UiCard
    title="Integração por API"
    description="Como um sistema externo envia transações para esta organização. Os valores abaixo são exemplos."
  >
    <div class="space-y-6">
      <div class="flex min-w-0 flex-wrap items-center gap-2 rounded-lg border border-ink/[0.08] px-3 py-2">
        <UiTag
          tone="brand"
          size="sm"
        >
          POST
        </UiTag>
        <code class="min-w-0 flex-1 break-all font-mono text-xs text-ink">{{ endpoint }}</code>
        <UiCopyButton
          :value="endpoint"
          label="Copiar endpoint"
          @failed="copyFailed"
        />
      </div>

      <ul class="grid gap-4 text-sm md:grid-cols-3">
        <li class="flex gap-3">
          <PhKey
            :size="18"
            class="mt-0.5 shrink-0 text-ink/65"
            aria-hidden="true"
          />
          <div>
            <p class="font-medium text-ink">
              Autenticação por API Key
            </p>
            <p class="mt-0.5 text-xs text-ink/65">
              Header <code class="font-mono">Authorization: Bearer</code>. A organização vem da chave; sessão e
              <code class="font-mono">X-Organization-Id</code> não valem aqui.
            </p>
          </div>
        </li>
        <li class="flex gap-3">
          <PhFingerprint
            :size="18"
            class="mt-0.5 shrink-0 text-ink/65"
            aria-hidden="true"
          />
          <div>
            <p class="font-medium text-ink">
              Idempotente por external_id
            </p>
            <p class="mt-0.5 text-xs text-ink/65">
              Pode reenviar com segurança após um timeout: o banco garante uma única transação por
              <code class="font-mono">external_id</code> na organização.
            </p>
          </div>
        </li>
        <li class="flex gap-3">
          <PhListMagnifyingGlass
            :size="18"
            class="mt-0.5 shrink-0 text-ink/65"
            aria-hidden="true"
          />
          <div>
            <p class="font-medium text-ink">
              Rastreável
            </p>
            <p class="mt-0.5 text-xs text-ink/65">
              Toda resposta traz <code class="font-mono">X-Request-Id</code>; envie o seu próprio para correlacionar logs.
            </p>
          </div>
        </li>
      </ul>

      <div>
        <div class="mb-2 flex items-center justify-between gap-2">
          <h3 class="text-sm font-semibold text-ink">
            Exemplo
          </h3>
          <UiCopyButton
            :value="curlExample"
            label="Copiar exemplo"
            text="Copiar"
            variant="outline"
            @failed="copyFailed"
          />
        </div>
        <pre
          class="overflow-x-auto rounded-lg bg-ink px-4 py-3 font-mono text-[11px] leading-relaxed text-white/90 sm:text-xs"
          tabindex="0"
          aria-label="Exemplo de requisição com curl"
        ><code>{{ curlExample }}</code></pre>
        <p class="mt-2 text-xs text-ink/65">
          Defina <code class="font-mono">PULSEBOARD_API_KEY</code> com a chave criada acima e use o SKU de um produto cadastrado em Produtos.
          O cliente é reconhecido pelo <code class="font-mono">external_id</code> ou criado na primeira venda.
        </p>
      </div>

      <div class="grid gap-6 lg:grid-cols-2">
        <div>
          <h3 class="mb-2 text-sm font-semibold text-ink">
            Respostas
          </h3>
          <ul class="divide-y divide-ink/[0.06]">
            <li
              v-for="response in RESPONSES"
              :key="response.status"
              class="flex gap-3 py-2 text-xs"
            >
              <UiTag
                :tone="response.tone"
                size="sm"
                class="w-11 justify-center font-mono"
              >
                {{ response.status }}
              </UiTag>
              <p class="min-w-0 text-ink/65">
                <span class="font-medium text-ink">{{ response.title }}.</span>
                {{ response.detail }}
              </p>
            </li>
          </ul>
        </div>
        <div>
          <h3 class="mb-2 text-sm font-semibold text-ink">
            Depois do envio
          </h3>
          <ul class="space-y-3">
            <li
              v-for="item in FOLLOW_UP"
              :key="item.path"
              class="text-xs"
            >
              <p class="flex min-w-0 flex-wrap items-center gap-2">
                <UiTag
                  :tone="item.method === 'GET' ? 'info' : 'brand'"
                  size="sm"
                >
                  {{ item.method }}
                </UiTag>
                <code class="break-all font-mono text-ink">{{ item.path }}</code>
              </p>
              <p class="mt-1 text-ink/65">
                {{ item.detail }}
              </p>
            </li>
          </ul>
          <p class="mt-4 text-xs text-ink/65">
            Transações recebidas aparecem em
            <NuxtLink
              to="/transactions"
              class="font-medium text-ink underline-offset-2 hover:underline"
            >Transações</NuxtLink>
            com origem "Integração", buscáveis pelo ID externo, e entram nos indicadores do Dashboard quando pagas.
          </p>
        </div>
      </div>
    </div>
  </UiCard>
</template>
