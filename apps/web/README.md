# PulseBoard Web

Frontend do PulseBoard: SPA em Nuxt 4 (`ssr: false`) que consome a API Laravel em `apps/api` via Sanctum SPA (cookie de sessão + CSRF).

## Requisitos

- bun
- API rodando em `http://localhost:8000` (ver README da raiz)
- O frontend precisa rodar em `http://localhost:3000`: é o único domínio stateful/CORS configurado na API.

## Comandos

```bash
cp .env.example .env
bun install
bun run dev          # http://localhost:3000
bun run test         # Vitest (unit + nuxt environment)
bun run typecheck
bun run lint         # lint:fix para corrigir
bun run generate     # build estático (.output/public)
bun run test:e2e     # Playwright smoke (API + web rodando, seed aplicado)
```

Variáveis (`.env`):

| Variável | Padrão |
|----------|--------|
| `NUXT_PUBLIC_API_URL` | `http://localhost:8000/api/v1` |
| `NUXT_PUBLIC_API_ORIGIN` | `http://localhost:8000` (usado para `/sanctum/csrf-cookie`) |

O smoke e2e usa `test@example.com` / `password`, cria e remove um produto `E2E smoke <timestamp>` e aceita `E2E_BASE_URL` para apontar para outro host. Usa o Chrome instalado (`channel: 'chrome'`).

## Arquitetura

```text
app/
├── pages/           # rotas; só composição e estado de UI
├── components/      # ui/ (primitivos reka-ui), data/, charts/, layout/ e por domínio
├── composables/     # estado de tela, useAsyncData, filtros na URL
├── repositories/    # uma função por endpoint, tipadas
├── stores/          # Pinia: apenas sessão (usuário + organização)
├── types/           # contratos da API
└── utils/           # formatação, datas, comparação, erros
```

Fluxo de dados: **página/componente → composable → repository → `useApiClient`**. Nenhuma página ou componente faz HTTP diretamente.

- **`useApiClient`**: `credentials: 'include'`, header `X-Organization-Id`, busca o cookie CSRF sob demanda antes da primeira mutação e repete a requisição uma vez em 419. Erros são normalizados (`utils/api-error.ts`); 401 limpa a sessão e redireciona para `/login?redirect=…`; 422 vira erros por campo nos formulários (`useFormErrors`).
- **Sessão**: nenhum token no `localStorage`. A organização ativa é lembrada em cookie (só o id) e validada contra `/auth/me`. Trocar de organização descarta os dados em cache e volta ao dashboard.
- **Permissões**: `usePermissions` esconde ações exclusivas de owner (exclusão). A API continua sendo a autoridade.
- **Estado na URL**: busca, filtros, paginação, período (`?period=7d|30d|90d|12m|mtd|last-month` ou `?from=&to=`), granularidade, ordenação e limite dos rankings vivem na query string, então dá para compartilhar links e usar voltar/avançar.
- **Carregamento**: `useAsyncData` com chave estável por organização. Ao mudar filtros, os dados anteriores ficam visíveis (esmaecidos) até a nova resposta, sem piscar skeleton.

## Telas

| Rota | Conteúdo |
|------|----------|
| `/login`, `/register` | Auth com erros por campo |
| `/dashboard` | KPIs com comparação, receita no tempo, status das transações, top 5 produtos/clientes |
| `/analytics/revenue` | Série de receita/pedidos com granularidade dia/semana/mês e tabela dos buckets |
| `/analytics/products` | Ranking por receita ou unidades (10/25/50) |
| `/analytics/customers` | Novos vs recorrentes e ranking por receita ou pedidos |
| `/analytics/transactions` | Distribuição por status (inclui não pagos) |
| `/products`, `/customers` | Listagem, busca, CRUD em diálogo, detalhe com métricas; exclusão só para owner |
| `/transactions` | Somente leitura: filtros por status, busca, cliente e período; detalhe com itens |

Regras da API refletidas na UI: dinheiro chega como string decimal e é formatado com a moeda da organização; datas seguem a timezone da organização; `change: null` aparece como "sem base de comparação"; entidades removidas aparecem no histórico sem link.

## Gráficos

Chart.js via `vue-chartjs`, em componentes `*.client.vue` carregados com `Lazy*` (chunk separado, fora do bundle inicial). As cores vêm dos tokens CSS (`utils/chart-theme.ts`). Cada gráfico tem `role="img"` e uma tabela equivalente para leitores de tela.

## Acessibilidade e qualidade

- axe-core sem violações nas páginas principais (390 px e 1440 px); contraste mínimo de texto secundário `ink/65`.
- Skip link, foco preso e devolvido em diálogos/drawers, menus operáveis por teclado, tabelas com overflow viram região rolável focável.
- Lighthouse (build estático): desktop 100 em performance; mobile 93–95; acessibilidade e boas práticas 100.

## Testes

- `tests/unit`: utilitários puros (dinheiro, datas, períodos, comparação, analytics, navegação).
- `tests/nuxt`: composables e páginas montadas no ambiente Nuxt, com os repositories mockados (`vi.mock`) usando fixtures no formato das respostas reais da API.
- `tests/e2e`: smoke Playwright contra a API real.
