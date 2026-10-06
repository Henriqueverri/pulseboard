# Testes

Estratégia de testes e como rodar cada suíte. Visão geral no [README](../README.md).

| Suíte | Onde | Ferramenta | Banco |
|-------|------|-----------|-------|
| API | `apps/api/tests` | PHPUnit | SQLite em memória localmente; PostgreSQL 16 na CI |
| Web | `apps/web/tests/unit`, `apps/web/tests/nuxt` | Vitest + @nuxt/test-utils (happy-dom) | — (repositories mockados) |
| E2E | `apps/web/tests/e2e` | Playwright | PostgreSQL descartável |

## O que é coberto

**API** (`tests/Feature`, `tests/Unit`)

- Auth: cadastro, login, logout, `/auth/me` e sessão.
- Multi-tenancy: header ausente/inválido, membership, recursos de outra organização (404), `organization_id` no payload ou na query (422), isolamento de listagens e de números agregados.
- CRUD de produtos e clientes: validação, unicidade por organização (incluindo soft-deleted), exclusão restrita a `owner`, soft delete quando há histórico.
- Transactions: filtros, busca (inclusive por `external_id` exato, sem atravessar organizações), período no fuso da organização, `source` e `status_history` na ordem do ciclo de vida, somente leitura (405 para escrita).
- Analytics: cada endpoint com dados controlados, consistência sobre o dataset de demo, consistência cruzada entre endpoints, orçamento fixo de consultas, timezone e horário de verão.
- API Keys (`ApiKeyManagementTest`, `ApiKeyGeneratorTest`): segredo só na resposta de criação, nunca na listagem; owner cria e revoga, member só lista; opções de expiração; 24 h na demo; limite de 10 ativas; revogação idempotente que para de autenticar na hora; chave de outra organização → 404.
- Ingestão (`tests/Feature/Api/Ingest`, `TransactionFingerprintTest`, `TransactionStatusTransitionsTest`):
  - autenticação por chave: ausente, malformada, desconhecida, revogada e expirada recebem o mesmo 401; `last_used_at` com resolução de um minuto; o Bearer não autentica rotas internas (`InternalRoutesRejectBearerTest`);
  - validação de cada campo, resolução de produto por SKU e de cliente por `external_id`, moeda e total com `code` estável;
  - idempotência: replay 200, conflito 409, itens em outra ordem, fingerprint;
  - lifecycle: todas as transições permitidas e proibidas, replay por estado, cronologia, histórico em cadeia;
  - isolamento de tenant: o header de organização é ignorado e recursos de outra organização são indistinguíveis de inexistentes;
  - concorrência real (`IngestConcurrencyTest`, só PostgreSQL): requisições simultâneas com o mesmo `external_id` geram uma transação; transições simultâneas aplicam uma;
  - orçamento de consultas (`IngestQueryBudgetTest`): 1 item e 100 itens usam o mesmo número de queries;
  - do sistema externo ao dashboard (`IngestAnalyticsTest`): uma venda ingerida muda os KPIs na próxima leitura, e as invariantes entre endpoints continuam valendo;
  - logs: uma linha por requisição, sem chave, header `Authorization` ou dados pessoais.
- Operação: health check, erros JSON em `api/*`, `X-Request-Id` em toda resposta (inclusive 500) e exposto no CORS, trusted proxies (HTTPS e rate limit de login pelo IP real), backfill do histórico de status, `pulseboard:demo` (inclusive o opt-in de insights só da demo e só com `AI_ENABLED=true`), `pulseboard:release` (inclusive a retenção de IA no start), `pulseboard:ai-prune` (retenção por tabela, todas as organizações, nenhuma outra tabela, mínimo de 32 dias para `ai_runs`) e `pulseboard:ai-usage` (agrupamento, percentis e orçamento).
- Proteções de IA: orçamento mensal global (soma entre organizações, virada do mês em UTC, `0` bloqueia, cache continua servido) e cota diária por IP só na demo (login compartilhado, outro IP passa, zera à meia-noite da demo, recusas por outra cota não consomem).

**Web**

- Utilitários puros: dinheiro, datas civis e fuso, períodos, comparação, navegação, erros de API (mensagens de 403, 422 e 429 com `Retry-After`).
- `useApiClient`: credenciais, header de organização, CSRF sob demanda, retry em 419, 401 → login, leitura de `Retry-After`, `X-Request-Id` e `code`.
- Store de sessão, organização lembrada e troca de organização.
- Páginas e composables montados no ambiente Nuxt com fixtures no formato real das respostas da API (listas, filtros na URL, formulários com 422, permissões, dashboard e analytics).
- Transações: origem e `external_id` na lista, linha do tempo na ordem da API, histórico vazio, ID da requisição no erro.
- API Keys: visão de member sem ações; o segredo aparece uma vez e depois não está no DOM, no payload, no `localStorage` nem no `sessionStorage`; cópia; aviso de expiração limitada; 422 de limite e de campo; 403; revogação com confirmação; o guia de integração só com placeholders.

**E2E** — três fluxos contra o build de produção e a API real:

- `insights.spec.ts` (API com `AI_ENABLED=true` e `AI_PROVIDER=scripted`: nenhuma chave, nenhuma chamada à OpenAI): o opt-in da demo feito pelo `pulseboard:demo` aparece ligado em Configurações › Insights → o dashboard em "últimos 90 dias" mostra o estado "ainda não gerado" → gerar → resultado com o rótulo de IA, o foco de volta no título, o achado e a ressalva de período parcial → a evidência mostra o mesmo valor do KPI de Receita → axe sem violações (configurações, estado inicial e resultado em desktop e mobile) → recarregar serve o cache sem gerar de novo → o link do achado leva ao destino com o mesmo período. O provedor roteirizado sempre escolhe o destino `dashboard`; o mapeamento dos outros destinos fica nos testes Vitest.

- `smoke.spec.ts`: login → dashboard → criar, editar e excluir um produto → abrir uma transação e ver o ciclo de vida → navegar pelas abas de analytics mantendo o período.
- `integration.spec.ts`: criar uma API Key pela UI e ler o segredo uma vez → axe sem violações na página de API Keys (desktop, mobile e com o diálogo do segredo aberto) → ingerir uma transação `pending` com a chave, reenviar (200) e marcar como paga → achar a transação pelo `external_id` e conferir a linha do tempo → revogar a chave e receber 401 `invalid_api_key`. A chave é criada pelo próprio teste, em tempo de execução: nenhuma chave fica no repositório nem nos secrets da CI.

## API

```bash
cd apps/api
php artisan test          # SQLite em memória (padrão do phpunit.xml)
vendor/bin/pint --test    # estilo (vendor/bin/pint corrige)
```

No SQLite, 10 testes são pulados com o motivo informado: os de horário de verão (buckets exatos por timezone) e os de concorrência da ingestão (conexões separadas, locks de linha e commits concorrentes). Eles exigem PostgreSQL, que é o banco de produção e o da CI. Para rodar a suíte inteira em PostgreSQL (como a CI), use um banco separado — `RefreshDatabase` apaga o banco usado.

Fixe **todas** as variáveis de conexão no Postgres local. Sobrescrever só `DB_DATABASE` mantém o host, o usuário e o `DB_URL` do `.env`: se ele apontar para um banco remoto, os testes rodam lá (e o `migrate` do Laravel cria o banco se ele não existir).

```bash
export DB_CONNECTION=pgsql DB_URL= DB_HOST=127.0.0.1 DB_PORT=5432 DB_USERNAME=pulseboard DB_PASSWORD=pulseboard
docker exec pulseboard-postgres createdb -U pulseboard pulseboard_test
DB_DATABASE=pulseboard_test php artisan test
```

## Avaliação de IA

Fora do PHPUnit: um comando que roda os casos de `tests/AiEval/cases` pelo serviço real do resumo, num SQLite em memória próprio (não toca no banco do `.env`), e grava um relatório em `storage/ai-eval/reports/`. Detalhes dos casos e avaliadores em [ai.md](ai.md#avaliação-do-resumo-implementada).

```bash
cd apps/api
php artisan pulseboard:ai-eval                         # provedor roteirizado: determinístico, sem rede (o que a CI roda)
php artisan pulseboard:ai-eval --case=known_period     # um caso
php artisan pulseboard:ai-eval --provider=openai --model=gpt-6-luna --repeat=3   # modelo real: exige OPENAI_API_KEY e custa dinheiro
php artisan pulseboard:ai-eval --provider=openai --record                        # grava as respostas reais como fixtures dos testes de parsing
```

O comando sai com código 1 se algum caso falhar ou se uma métrica ficar abaixo do threshold, e se recusa a rodar com `APP_ENV=production`.

## Web

```bash
cd apps/web
bun run test        # Vitest
bun run typecheck
bun run lint        # bun run lint:fix corrige
bun run generate    # build estático em .output/public
```

## E2E (Playwright)

Os testes fazem login com a conta owner da demo (`pulseboard:demo`) e escrevem no banco (o fluxo de integração cria uma chave e uma transação, que não podem ser apagadas; o de insights grava um resumo em cache), então use um banco descartável, recém-criado, nunca o de desenvolvimento nem o de produção. A API sobe com `AI_ENABLED=true AI_PROVIDER=scripted`, para o `pulseboard:demo` ativar os insights da demo e a geração usar o provedor roteirizado. Para rodar de novo o fluxo de insights sem recriar o banco, apague antes o resumo gerado (`delete from ai_insights;`). A senha vem de `DEMO_PASSWORD`, que o `pulseboard:demo` e o Playwright leem do ambiente; use uma senha descartável (a CI gera uma por execução). `E2E_EMAIL` / `E2E_PASSWORD` sobrescrevem a conta, se precisar. Com o front de produção servido em `localhost:3000` (pare o `bun run dev` antes):

```bash
docker exec pulseboard-postgres createdb -U pulseboard pulseboard_e2e
export DEMO_PASSWORD="$(openssl rand -hex 24)"   # nos três terminais (ou rode tudo no mesmo shell)
export DB_CONNECTION=pgsql DB_URL= DB_HOST=127.0.0.1 DB_PORT=5432 DB_USERNAME=pulseboard DB_PASSWORD=pulseboard
export AI_ENABLED=true AI_PROVIDER=scripted        # insights com o provedor roteirizado, sem chave

# terminal 1 — API no banco descartável
cd apps/api
DB_DATABASE=pulseboard_e2e php artisan migrate:fresh --force
DB_DATABASE=pulseboard_e2e php artisan pulseboard:demo
DB_DATABASE=pulseboard_e2e php artisan serve --port=8001

# terminal 2 — build de produção apontando para essa API
cd apps/web
NUXT_PUBLIC_API_URL=http://localhost:8001/api/v1 NUXT_PUBLIC_API_ORIGIN=http://localhost:8001 bun run generate
bun run serve:static

# terminal 3 — o fluxo de integração chama a API de ingestão direto
cd apps/web
E2E_API_URL=http://localhost:8001/api/v1 bun run test:e2e
```

`serve:static` serve o build como o Cloudflare Pages em modo SPA (qualquer rota desconhecida recebe o `index.html`). O Playwright usa o Google Chrome instalado (`E2E_CHANNEL` troca o canal) e aceita `E2E_BASE_URL` para outro host. O fluxo de integração envia as requisições de ingestão para `E2E_API_URL` (ou `NUXT_PUBLIC_API_URL`; padrão `http://localhost:8000/api/v1`, a porta da CI). O check de acessibilidade usa `@axe-core/playwright` com as regras WCAG 2.1 A e AA.

## CI

[`.github/workflows/ci.yml`](../.github/workflows/ci.yml) roda em push na `main` e em pull requests:

| Job | O que valida |
|-----|--------------|
| `api` | `composer install`, Pint, `migrate` em PostgreSQL 16 limpo, PHPUnit completo em PostgreSQL (sem os skips do SQLite), avaliação de IA com o provedor roteirizado |
| `web` | `bun install --frozen-lockfile`, lint, typecheck, Vitest, `nuxt generate` |
| `e2e` | PostgreSQL descartável com `migrate:fresh` + `pulseboard:demo` (`DEMO_PASSWORD` aleatória por execução, mascarada nos logs) → API (`artisan serve`, com `AI_ENABLED=true` e `AI_PROVIDER=scripted`) → build estático (`serve:static`) → Playwright (insights, smoke e fluxo de integração, com axe); logs e traces como artefato em caso de falha |

O `e2e` só roda depois que `api` e `web` passam. O deploy da API no Render espera todos os checks.
