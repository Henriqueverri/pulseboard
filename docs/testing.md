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
- Transactions: filtros, busca, período no fuso da organização, somente leitura (405 para escrita).
- Analytics: cada endpoint com dados controlados, consistência sobre o dataset de demo, consistência cruzada entre endpoints, orçamento fixo de consultas, timezone e horário de verão.
- Operação: health check, erros JSON em `api/*`, trusted proxies (HTTPS e rate limit de login pelo IP real), `pulseboard:demo` e `pulseboard:release`.

**Web**

- Utilitários puros: dinheiro, datas civis e fuso, períodos, comparação, navegação, erros de API.
- `useApiClient`: credenciais, header de organização, CSRF sob demanda, retry em 419, 401 → login.
- Store de sessão, organização lembrada e troca de organização.
- Páginas e composables montados no ambiente Nuxt com fixtures no formato real das respostas da API (listas, filtros na URL, formulários com 422, permissões, dashboard e analytics).

**E2E** — um smoke do fluxo principal: login → dashboard → criar, editar e excluir um produto → abrir uma transação → navegar pelas abas de analytics mantendo o período.

## API

```bash
cd apps/api
php artisan test          # SQLite em memória (padrão do phpunit.xml)
vendor/bin/pint --test    # estilo (vendor/bin/pint corrige)
```

No SQLite, 2 testes de horário de verão (buckets exatos por timezone) são pulados: eles exigem PostgreSQL, que é o banco de produção. Para rodar a suíte inteira em PostgreSQL (como a CI), use um banco separado — `RefreshDatabase` apaga o banco usado.

Fixe **todas** as variáveis de conexão no Postgres local. Sobrescrever só `DB_DATABASE` mantém o host, o usuário e o `DB_URL` do `.env`: se ele apontar para um banco remoto, os testes rodam lá (e o `migrate` do Laravel cria o banco se ele não existir).

```bash
export DB_CONNECTION=pgsql DB_URL= DB_HOST=127.0.0.1 DB_PORT=5432 DB_USERNAME=pulseboard DB_PASSWORD=pulseboard
docker exec pulseboard-postgres createdb -U pulseboard pulseboard_test
DB_DATABASE=pulseboard_test php artisan test
```

## Web

```bash
cd apps/web
bun run test        # Vitest
bun run typecheck
bun run lint        # bun run lint:fix corrige
bun run generate    # build estático em .output/public
```

## E2E (Playwright)

O smoke faz login com a conta owner da demo (`pulseboard:demo`) e escreve no banco, então use um banco descartável, nunca o de desenvolvimento nem o de produção. A senha vem de `DEMO_PASSWORD`, que o `pulseboard:demo` e o Playwright leem do ambiente; use uma senha descartável (a CI gera uma por execução). `E2E_EMAIL` / `E2E_PASSWORD` sobrescrevem a conta, se precisar. Com o front de produção servido em `localhost:3000` (pare o `bun run dev` antes):

```bash
docker exec pulseboard-postgres createdb -U pulseboard pulseboard_e2e
export DEMO_PASSWORD="$(openssl rand -hex 24)"   # nos três terminais (ou rode tudo no mesmo shell)
export DB_CONNECTION=pgsql DB_URL= DB_HOST=127.0.0.1 DB_PORT=5432 DB_USERNAME=pulseboard DB_PASSWORD=pulseboard

# terminal 1 — API no banco descartável
cd apps/api
DB_DATABASE=pulseboard_e2e php artisan migrate:fresh --force
DB_DATABASE=pulseboard_e2e php artisan pulseboard:demo
DB_DATABASE=pulseboard_e2e php artisan serve --port=8001

# terminal 2 — build de produção apontando para essa API
cd apps/web
NUXT_PUBLIC_API_URL=http://localhost:8001/api/v1 NUXT_PUBLIC_API_ORIGIN=http://localhost:8001 bun run generate
bun run serve:static

# terminal 3
cd apps/web
bun run test:e2e
```

`serve:static` serve o build como o Cloudflare Pages em modo SPA (qualquer rota desconhecida recebe o `index.html`). O Playwright usa o Google Chrome instalado (`E2E_CHANNEL` troca o canal) e aceita `E2E_BASE_URL` para outro host.

## CI

[`.github/workflows/ci.yml`](../.github/workflows/ci.yml) roda em push na `main` e em pull requests:

| Job | O que valida |
|-----|--------------|
| `api` | `composer install`, Pint, `migrate` em PostgreSQL 16 limpo, PHPUnit completo em PostgreSQL (sem os skips do SQLite) |
| `web` | `bun install --frozen-lockfile`, lint, typecheck, Vitest, `nuxt generate` |
| `e2e` | PostgreSQL descartável com `migrate:fresh` + `pulseboard:demo` (`DEMO_PASSWORD` aleatória por execução, mascarada nos logs) → API (`artisan serve`) → build estático (`serve:static`) → Playwright; logs e traces como artefato em caso de falha |

O `e2e` só roda depois que `api` e `web` passam. O deploy da API no Render espera todos os checks.
