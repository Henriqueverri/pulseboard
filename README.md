# PulseBoard

SaaS de analytics para pequenos negócios acompanharem vendas, receita, clientes e indicadores em um dashboard.

Este repositório é um monorepo de portfólio full stack: API Laravel com autenticação Sanctum SPA (cookie), multi-tenancy por Organization, CRUD de produtos e clientes, transações somente leitura e analytics (dashboard, receita, produtos, clientes e status); SPA Nuxt que consome essa API (detalhes do front em [`apps/web/README.md`](apps/web/README.md)).

## Stack

| Camada | Tecnologia |
|--------|------------|
| Frontend | Nuxt 4, Vue 3, TypeScript, Tailwind CSS, Pinia |
| Backend | Laravel 12, PHP 8.4+, API REST, Sanctum SPA |
| Banco | PostgreSQL 16 |
| Package FE | bun |
| Infra local | Docker Compose (somente Postgres no MVP) |

## Estrutura do monorepo

```text
pulseboard/
├── apps/
│   ├── web/          # Nuxt 4
│   └── api/          # Laravel 12
├── .github/workflows/ci.yml
├── docker-compose.yml
├── README.md
└── .gitignore
```

## Pré-requisitos

- PHP 8.4+ com extensão `pdo_pgsql` (o `composer.lock` usa componentes Symfony 8)
- Composer 2
- bun
- Docker + Docker Compose
- Node.js 20+ (opcional; o front usa bun)

## Como iniciar localmente

### 1. PostgreSQL

Na raiz do repositório:

```bash
docker compose up -d
```

Credenciais padrão (apenas local):

- Host: `127.0.0.1`
- Port: `5432`
- Database / user / password: `pulseboard`

### 2. API (Laravel)

```bash
cd apps/api
cp .env.example .env   # se ainda não existir
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8000
```

`migrate:fresh --seed` recria o banco local do zero com o dataset de demonstração (1 organização, 40 produtos, 70 clientes, ~440 transações em 90 dias). Usuários: `test@example.com` (owner) e `member@example.com` (member), senha `password`.

- API: http://localhost:8000  
- Health: http://localhost:8000/api/v1/health  
- CSRF: http://localhost:8000/sanctum/csrf-cookie  

### 3. Frontend (Nuxt)

```bash
cd apps/web
cp .env.example .env   # se ainda não existir
bun install
bun run dev
```

- Frontend: http://localhost:3000  
- Login: http://localhost:3000/login  
- Register: http://localhost:3000/register  

O front precisa rodar em `localhost:3000`: é a única origem liberada em CORS e em `SANCTUM_STATEFUL_DOMAINS` no `.env` local.

## Testes e qualidade

### API

```bash
cd apps/api
php artisan test          # SQLite em memória (padrão do phpunit.xml)
vendor/bin/pint --test    # estilo (vendor/bin/pint corrige)
```

No SQLite, 2 testes de horário de verão (buckets exatos por timezone) são pulados: eles exigem PostgreSQL, que é o banco de produção. Para rodar a suíte inteira em PostgreSQL (como a CI), use um banco separado — `RefreshDatabase` apaga o banco usado:

```bash
docker exec pulseboard-postgres createdb -U pulseboard pulseboard_test
DB_CONNECTION=pgsql DB_DATABASE=pulseboard_test php artisan test
```

### Web

```bash
cd apps/web
bun run test        # Vitest
bun run typecheck
bun run lint        # bun run lint:fix corrige
bun run generate    # build estático em .output/public
```

### E2E (Playwright)

O smoke faz login, abre o dashboard, cria/edita/remove um produto, abre uma transação e navega pelas abas de analytics. Ele escreve no banco, então use um banco descartável com o seed, nunca o de desenvolvimento. Com o front de produção servido em `localhost:3000` (pare o `bun run dev` antes):

```bash
docker exec pulseboard-postgres createdb -U pulseboard pulseboard_e2e

# terminal 1 — API no banco descartável
cd apps/api
DB_DATABASE=pulseboard_e2e php artisan migrate:fresh --seed --force
DB_DATABASE=pulseboard_e2e php artisan serve --port=8001

# terminal 2 — build de produção apontando para essa API
cd apps/web
NUXT_PUBLIC_API_URL=http://localhost:8001/api/v1 NUXT_PUBLIC_API_ORIGIN=http://localhost:8001 bun run generate
bun run serve:static

# terminal 3
cd apps/web
bun run test:e2e
```

`serve:static` serve o build como o Cloudflare Pages em modo SPA (qualquer rota desconhecida recebe o `index.html`). O Playwright usa o Google Chrome instalado (`E2E_CHANNEL` troca o canal).

## CI

`.github/workflows/ci.yml` roda em push na `main` e em pull requests:

| Job | O que valida |
|-----|--------------|
| `api` | `composer install`, Pint, `migrate` em PostgreSQL 16 limpo, PHPUnit completo em PostgreSQL (sem os skips do SQLite) |
| `web` | `bun install --frozen-lockfile`, lint, typecheck, Vitest, `nuxt generate` |
| `e2e` | PostgreSQL descartável com `migrate:fresh --seed` → API (`artisan serve`) → build estático (`serve:static`) → Playwright; logs e traces como artefato em caso de falha |

Deploy não roda na CI: Railway e Cloudflare Pages fazem deploy pela integração nativa com o GitHub.

## Autenticação (Sanctum SPA)

Fluxo cookie httpOnly + CSRF (sem token no `localStorage`):

1. `GET /sanctum/csrf-cookie` (`credentials: include`)
2. `POST /api/v1/auth/register` ou `/login`
3. Requests seguintes com cookie de sessão
4. Contexto de tenant via header `X-Organization-Id` (contexto apenas; autorização = membership)

## API de negócio (`/api/v1`)

Rotas de domínio exigem sessão autenticada + `X-Organization-Id` de uma organização da qual o usuário é membro.

| Recurso | Rotas | Listagem |
|---------|-------|----------|
| Products | `GET/POST /products`, `GET/PUT/PATCH/DELETE /products/{id}` | `status`, `q` (nome ou SKU), `page`, `per_page` (1–100, padrão 15) |
| Customers | `GET/POST /customers`, `GET/PUT/PATCH/DELETE /customers/{id}` | `q` (nome ou e-mail), `page`, `per_page` (1–100, padrão 15) |

- Listagens paginadas (formato padrão do Laravel: `data`, `links`, `meta`), ordenadas por `name` e depois `id`.
- `GET /products/{id}` inclui `units_sold` e `revenue`; `GET /customers/{id}` inclui `orders_count`, `total_spent` e as 5 `recent_transactions` mais recentes. As métricas consideram apenas transações `paid`.
- `organization_id` nunca é aceito no payload (422); o tenant vem sempre do contexto.
- Registro de outra organização → 404. Header de organização sem membership → 403.
- `DELETE` é restrito a `owner`. Sem histórico (nenhuma venda/transação) o registro é removido; com histórico é feito soft delete e as transações permanecem intactas.
- SKU e e-mail são únicos por organização **incluindo registros soft-deleted** (o índice único não é parcial). E-mails são normalizados para minúsculas.

### Transactions (read-only)

No MVP, Transactions são registros históricos (gerados pelo seed) e **não podem ser criadas, alteradas ou removidas pela API** — só existem `GET /transactions` e `GET /transactions/{id}`; outros métodos retornam 405.

| Filtro | Formato | Regra |
|--------|---------|-------|
| `status` | `paid`, `refunded`, `pending`, `canceled` | Sem filtro, todos os status são retornados |
| `q` | texto | UUID → match exato no id da transaction; outro texto → nome ou e-mail do customer (case-insensitive) |
| `customer_id` | UUID | Customer de outra organização resulta em lista vazia |
| `from` / `to` | `YYYY-MM-DD` | Dias do calendário na timezone da Organization (`organizations.timezone`, padrão `America/Sao_Paulo`): `from` = início do dia, `to` = fim do dia; `from` > `to` → 422 |
| `page` / `per_page` | inteiro | `per_page` 1–100, padrão 15 |

Ordenação: `occurred_at` desc, depois `id` desc. A listagem traz `customer` e `items_count`; o detalhe traz `customer` e `items` com `product`. Customers/products soft-deleted continuam aparecendo no histórico com `is_deleted: true`. `unit_price` é o preço no momento da venda. `total_amount`, `unit_price` e `line_total` são strings com 2 casas.

- **Compatível com analytics:** `from` / `to` usam o mesmo calendário local dos endpoints de analytics. Com os mesmos `from` / `to`, `meta.total` com `status=paid` é igual a `orders` do `/dashboard`, e sem filtro de status é igual à soma de `orders` de `/analytics/transactions`.
- **Tenant e permissões:** transação de outra organização → 404; header sem membership → 403; owner e member podem ler.
- **Consultas:** número fixo por request (listagem: contagem, página com `items_count` e customers; detalhe: transação, customer, itens e produtos), independente do número de resultados ou de itens.

```http
GET /api/v1/transactions?status=paid&from=2026-09-01&to=2026-09-30&q=maria&per_page=20
X-Organization-Id: {organization-uuid}
Accept: application/json
```

```json
{
  "data": [
    {
      "id": "9d1c…",
      "status": "paid",
      "total_amount": "159.50",
      "occurred_at": "2026-09-15T14:32:00.000000Z",
      "items_count": 2,
      "customer": { "id": "9d1b…", "name": "Maria Souza", "email": "maria@example.com", "is_deleted": false }
    }
  ],
  "links": { "first": "…?page=1", "last": "…?page=1", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 20, "total": 1, "last_page": 1 }
}
```

`GET /api/v1/transactions/{id}`:

```json
{
  "data": {
    "id": "9d1c…",
    "status": "paid",
    "total_amount": "159.50",
    "occurred_at": "2026-09-15T14:32:00.000000Z",
    "customer": { "id": "9d1b…", "name": "Maria Souza", "email": "maria@example.com", "is_deleted": false },
    "items": [
      {
        "id": "9d1d…",
        "quantity": 2,
        "unit_price": "49.90",
        "line_total": "99.80",
        "product": { "id": "9d1a…", "name": "Mouse sem fio Pro", "sku": "PB-1234-ab", "is_deleted": false }
      }
    ]
  }
}
```

### Analytics — regras comuns

| Endpoint | Conteúdo | Parâmetros além de `from` / `to` | Consultas por request |
|----------|----------|----------------------------------|-----------------------|
| `GET /api/v1/dashboard` | KPIs: receita, pedidos, ticket médio, clientes | — | 1 |
| `GET /api/v1/analytics/revenue` | Série temporal da receita + resumo | `granularity` | 2 |
| `GET /api/v1/analytics/products` | Ranking de produtos + resumo | `sort`, `limit` | 3 |
| `GET /api/v1/analytics/customers` | Ranking de clientes + indicadores da base | `sort`, `limit` | 4 |
| `GET /api/v1/analytics/transactions` | Distribuição por status | — | 1 |

Todos são somente leitura (`GET`/`HEAD`; outros métodos → 405) e seguem as mesmas regras:

- **Timezone da Organization:** `organizations.timezone` (IANA, padrão `America/Sao_Paulo`) define o calendário de negócio. O banco guarda UTC; os limites do período são calculados na aplicação e convertidos para UTC. Não existe parâmetro `timezone` (se enviado, é ignorado) e o cliente nunca escolhe a timezone.
- **Período:** `from` / `to` são datas locais da Organization (`YYYY-MM-DD`), inclusivas — `from` começa às 00:00 locais e `to` termina às 24:00 locais. Informados juntos, `to` ≥ `from`, no máximo 366 dias; fora disso → 422. Sem os dois, o padrão são os **últimos 30 dias incluindo hoje** (o dia atual ainda está em andamento, então o período padrão pode estar incompleto).
- **Período anterior:** mesmo número de dias, imediatamente antes de `from` (`01/09–30/09` compara com `02/08–31/08`). É devolvido em `meta.previous_period`.
- **Comparação:** cada métrica é `{ "value", "previous", "change" }`. `change` é a variação percentual com 1 casa decimal (`22.5` = +22,5%): `0.0` quando atual e anterior são zero; `null` quando o anterior é zero e o atual não, ou quando algum dos dois é `null`.
- **Dinheiro:** strings decimais com 2 casas (`"1250.00"`, `"0.00"` sem vendas), nunca float; a moeda vem em `meta.currency`. Contagens são inteiros; percentuais (só em status analytics) são floats com 1 casa.
- **Venda = transação `paid`:** Dashboard, Revenue, Products e Customers consideram somente transações com status `paid` e `occurred_at` no período. `pending`, `refunded` e `canceled` não são receita, pedido nem atividade de cliente. `average_order_value` é `null` quando não há pedidos.
- **Exceção — status analytics:** `/analytics/transactions` mostra todos os status. O `revenue` por status é `SUM(total_amount)` daquele status; somente a linha `paid` é receita no sentido do Dashboard.
- **`meta`:** `period`, `previous_period`, `timezone`, `currency` e os parâmetros específicos do endpoint (`granularity`, `sort`, `limit`).
- **Tenant isolation:** sessão Sanctum obrigatória (401 sem sessão) e header `X-Organization-Id` de uma organização da qual o usuário é membro (403 sem membership). Owner e member podem consultar. `organization_id` na query → 422. Todas as consultas partem da organização do contexto: dados de outra organização nunca aparecem nem alteram os números.

**Consistência entre endpoints** (coberta por testes sobre o seed e com dados controlados):

- `dashboard.revenue` = `revenue.summary.revenue` = `products.summary.revenue` = linha `paid` de `/analytics/transactions` (objeto `Comparison` completo, atual e anterior).
- `dashboard.orders` = `revenue.summary.orders` = `orders` da linha `paid` = `meta.total` de `GET /transactions?status=paid&from&to`.
- `dashboard.customers` = `customers.summary.active_customers`; `new_customers + returning_customers = active_customers`.
- Soma dos buckets (`day`, `week`, `month`) = `summary` do Revenue; soma do ranking completo de produtos = receita e unidades do resumo; soma do ranking completo de clientes = receita e pedidos do Dashboard.
- Soma de `orders` dos 4 status = `meta.total` de `GET /transactions?from&to`; soma do `revenue` dos 4 status = soma de `total_amount` de todas as transações do período.
- O `previous` de cada métrica é igual ao `value` de um request feito para o período anterior.
- Cada bucket diário ou mensal do Revenue tem o mesmo número de pedidos que `GET /transactions?status=paid` com o `from` / `to` do bucket: os dois usam o mesmo calendário local.

**Consultas e performance:** o número de consultas por request é fixo (tabela acima). Não cresce com o volume de transações, produtos ou clientes, com `limit`, com a granularidade nem com o tamanho do período, e não há N+1. Os períodos atual e anterior são lidos numa única varredura (`CASE` na data de início). Medido com `EXPLAIN ANALYZE` no PostgreSQL 16 em um banco descartável com 200 mil transações e 500 mil itens (organização principal com 120 mil transações em 2 anos): com 30 dias, cada consulta levou de ~2 a ~45 ms; com 366 dias, até ~300 ms (resumo de produtos, dominado pelo `COUNT(DISTINCT)` com `work_mem` padrão). Os índices existentes `transactions (organization_id, occurred_at)` e `(organization_id, status)` bastam: o candidato `(organization_id, status, occurred_at)` não trouxe ganho material e não foi criado.

**Limitações conhecidas:**

- O status usado é o **atual** da transação: um estorno ou cancelamento posterior muda retroativamente os números do período em que a venda ocorreu (não há histórico de mudanças de status).
- O período padrão inclui o dia de hoje, ainda incompleto.
- SQLite (suíte rápida de testes) não tem base de timezones: os buckets do Revenue usam o offset fixo do início do período (exato para `America/Sao_Paulo`, sem horário de verão); os testes de DST dos buckets rodam só no PostgreSQL. Os limites de período dos demais endpoints são calculados na aplicação e são exatos nos dois bancos.
- Sem cache: cada request consulta o banco.

### Dashboard

`GET /api/v1/dashboard` devolve os KPIs do período, cada um comparado ao período anterior.

| Parâmetro | Formato | Regra |
|-----------|---------|-------|
| `from` / `to` | `YYYY-MM-DD` | Dias do calendário na timezone da Organization; informados juntos; `to` ≥ `from`; no máximo 366 dias. Sem os dois, últimos 30 dias incluindo hoje |

- A timezone vem sempre de `organizations.timezone` (padrão `America/Sao_Paulo`); não existe parâmetro `timezone`. Os limites do período são convertidos para UTC antes da consulta (o banco guarda UTC).
- `organization_id` na query → 422. O tenant vem do header `X-Organization-Id` (membership validada).
- Período anterior: mesmo número de dias, imediatamente antes de `from` (ex.: `01/09–30/09` compara com `02/08–31/08`).

**KPIs** — consideram **somente transações `paid`** com `occurred_at` dentro do período:

| KPI | Definição | Tipo |
|-----|-----------|------|
| `revenue` | `SUM(total_amount)` | string decimal (`"0.00"` sem vendas) |
| `orders` | quantidade de transações pagas | inteiro |
| `average_order_value` | `revenue / orders`, arredondado ao centavo (half-up) | string decimal; `null` quando `orders = 0` |
| `customers` | clientes distintos com ao menos uma transação paga no período (inclui clientes soft-deleted que compraram) | inteiro |

**Comparação** — cada KPI é `{ "value", "previous", "change" }`. `change` é a variação percentual (número com 1 casa decimal, `22.5` = +22,5%): `0.0` quando atual e anterior são zero; `null` quando o anterior é zero e o atual não, ou quando algum valor é `null`.

```http
GET /api/v1/dashboard?from=2026-09-01&to=2026-09-30
X-Organization-Id: {organization-uuid}
Accept: application/json
```

```json
{
  "data": {
    "revenue": { "value": "12500.00", "previous": "10200.00", "change": 22.5 },
    "orders": { "value": 98, "previous": 81, "change": 21.0 },
    "average_order_value": { "value": "127.55", "previous": "125.93", "change": 1.3 },
    "customers": { "value": 52, "previous": 47, "change": 10.6 }
  },
  "meta": {
    "period": { "from": "2026-09-01", "to": "2026-09-30", "days": 30 },
    "previous_period": { "from": "2026-08-02", "to": "2026-08-31", "days": 30 },
    "timezone": "America/Sao_Paulo",
    "currency": "BRL"
  }
}
```

Sem vendas no período: `revenue` `"0.00"`, `orders` e `customers` `0`, `average_order_value` `null` (e `change` conforme as regras de comparação). Clientes e produtos soft-deleted não alteram o histórico: as vendas deles continuam contando.

Os KPIs saem de uma única consulta agregada no banco. Limitações conhecidas: o status é o estado atual da transação (um estorno remove a receita da data original da venda) e o período padrão inclui o dia de hoje ainda em andamento.

### Revenue analytics

`GET /api/v1/analytics/revenue` devolve a série temporal da receita (somente transações `paid`) no calendário da Organization.

| Parâmetro | Formato | Regra |
|-----------|---------|-------|
| `from` / `to` | `YYYY-MM-DD` | Mesmas regras do Dashboard (juntos, máx. 366 dias, padrão últimos 30 dias, timezone da Organization) |
| `granularity` | `day`, `week`, `month` | Padrão `day`; outro valor → 422 |

- **Buckets:** `day` = cada dia; `week` = semana ISO (segunda a domingo); `month` = mês do calendário. `bucket` é o primeiro dia local do bucket (numa semana parcial pode ser anterior a `from`); `from` / `to` são os dias efetivamente cobertos, recortados ao período — o primeiro e o último bucket podem ser parciais. Uma granularidade maior que o período gera um único bucket parcial.
- **Todos os buckets do período são retornados**, em ordem cronológica; sem vendas → `"revenue": "0.00"`, `"orders": 0`. A receita é agregada no banco (`GROUP BY` do bucket local) e os buckets vazios são preenchidos na aplicação, sem consultas extras.
- **Timezone:** uma venda em `2026-09-01T02:59:59Z` pertence ao dia `2026-08-31` em `America/Sao_Paulo`. No PostgreSQL a conversão usa a base de timezones (DST exato).
- **`summary`:** `revenue` e `orders` do período comparados ao anterior, com a mesma definição e formato do `/dashboard` (soma dos buckets = `summary.revenue.value` = `dashboard.revenue.value`).

```http
GET /api/v1/analytics/revenue?from=2026-09-02&to=2026-09-16&granularity=week
X-Organization-Id: {organization-uuid}
Accept: application/json
```

```json
{
  "data": [
    { "bucket": "2026-08-31", "from": "2026-09-02", "to": "2026-09-06", "revenue": "1250.00", "orders": 14 },
    { "bucket": "2026-09-07", "from": "2026-09-07", "to": "2026-09-13", "revenue": "0.00", "orders": 0 },
    { "bucket": "2026-09-14", "from": "2026-09-14", "to": "2026-09-16", "revenue": "830.40", "orders": 9 }
  ],
  "summary": {
    "revenue": { "value": "2080.40", "previous": "1904.10", "change": 9.3 },
    "orders": { "value": 23, "previous": 21, "change": 9.5 }
  },
  "meta": {
    "period": { "from": "2026-09-02", "to": "2026-09-16", "days": 15 },
    "previous_period": { "from": "2026-08-18", "to": "2026-09-01", "days": 15 },
    "timezone": "America/Sao_Paulo",
    "currency": "BRL",
    "granularity": "week"
  }
}
```

Soft delete não afeta a série: vendas de clientes e produtos excluídos continuam contando. Duas consultas agregadas por request (série + resumo), independentemente do volume ou do número de buckets (366 dias diários continuam sendo 2 consultas). Limitações: as mesmas do Dashboard (status atual, dia de hoje parcial). A suíte de testes rápida roda em SQLite, que não tem timezones: lá o bucket usa o offset fixo do início do período (exato para `America/Sao_Paulo`); os testes de DST rodam só no PostgreSQL.

### Product analytics

`GET /api/v1/analytics/products` devolve o ranking dos produtos vendidos no período (somente transações `paid`), cada um comparado ao período anterior.

| Parâmetro | Formato | Regra |
|-----------|---------|-------|
| `from` / `to` | `YYYY-MM-DD` | Mesmas regras do Dashboard (juntos, máx. 366 dias, padrão últimos 30 dias, timezone da Organization) |
| `sort` | `revenue`, `units_sold` | Padrão `revenue`; outro valor → 422 |
| `limit` | inteiro 1–50 | Padrão 10; fora da faixa → 422. Sem paginação |

- **Receita do produto:** `SUM(line_total)` dos itens pagos, ou seja, o preço no momento da venda (alterar `price` depois não muda o histórico). **Unidades:** `SUM(quantity)`.
- **Entra no ranking** o produto com ao menos uma venda paga no período atual. Produto vendido só no período anterior não aparece em `data`, mas conta em `summary.*.previous`.
- **Ordenação:** `sort=revenue` → receita, unidades, nome, id; `sort=units_sold` → unidades, receita, nome, id. `rank` começa em 1.
- **Produtos soft-deleted e `inactive`** com vendas continuam no ranking (`is_deleted: true` / `status: "inactive"`); compras de clientes soft-deleted também contam.
- **`summary` não depende de `limit`:** considera todos os produtos vendidos. `revenue` é o mesmo objeto do `/dashboard` (soma do ranking completo = `summary.revenue.value` = `dashboard.revenue.value`); `units_sold` e `products_sold` (produtos distintos) no mesmo formato `{ value, previous, change }`.
- **Timezone:** só os limites do período dependem da timezone da Organization; eles são calculados na aplicação e convertidos para UTC (DST exato também no SQLite).

```http
GET /api/v1/analytics/products?from=2026-09-01&to=2026-09-30&sort=revenue&limit=3
X-Organization-Id: {organization-uuid}
Accept: application/json
```

```json
{
  "data": [
    {
      "rank": 1,
      "product": { "id": "9d1a…", "name": "Mesa regulável Essential", "sku": "PB-013-ESS", "status": "active", "is_deleted": false },
      "revenue": { "value": "4649.70", "previous": "3099.80", "change": 50.0 },
      "units_sold": { "value": 3, "previous": 2, "change": 50.0 }
    },
    {
      "rank": 2,
      "product": { "id": "9d1b…", "name": "Monitor 24\" Pro", "sku": "PB-010-PRO", "status": "active", "is_deleted": false },
      "revenue": { "value": "2099.80", "previous": "0.00", "change": null },
      "units_sold": { "value": 2, "previous": 0, "change": null }
    },
    {
      "rank": 3,
      "product": { "id": "9d1c…", "name": "Mouse sem fio Essential", "sku": "PB-001-ESS", "status": "inactive", "is_deleted": true },
      "revenue": { "value": "799.00", "previous": "639.20", "change": 25.0 },
      "units_sold": { "value": 10, "previous": 8, "change": 25.0 }
    }
  ],
  "summary": {
    "revenue": { "value": "8120.40", "previous": "6650.30", "change": 22.1 },
    "units_sold": { "value": 31, "previous": 26, "change": 19.2 },
    "products_sold": { "value": 7, "previous": 6, "change": 16.7 }
  },
  "meta": {
    "period": { "from": "2026-09-01", "to": "2026-09-30", "days": 30 },
    "previous_period": { "from": "2026-08-02", "to": "2026-08-31", "days": 30 },
    "timezone": "America/Sao_Paulo",
    "currency": "BRL",
    "sort": "revenue",
    "limit": 3
  }
}
```

Sem vendas no período: `"data": []` e `summary` zerado (`change: 0.0` se o anterior também é zero, `null` se só o anterior é zero).

Três consultas agregadas por request (ranking com os dados do produto via join, totais de itens e KPIs do Dashboard), independentemente do número de produtos ou de `limit` — sem N+1. Limitações: as mesmas do Dashboard (status atual, dia de hoje parcial).

### Customer analytics

`GET /api/v1/analytics/customers` devolve os indicadores da base de clientes e o ranking dos clientes que mais compraram no período, tudo comparado ao período anterior.

| Parâmetro | Formato | Regra |
|-----------|---------|-------|
| `from` / `to` | `YYYY-MM-DD` | Mesmas regras do Dashboard (juntos, máx. 366 dias, padrão últimos 30 dias, timezone da Organization) |
| `sort` | `revenue`, `orders` | Padrão `revenue`; outro valor → 422 |
| `limit` | inteiro 1–50 | Padrão 10; fora da faixa → 422. Sem paginação |

**Summary** — cada métrica no formato `{ value, previous, change }`, sempre sobre todos os clientes (independe de `limit`):

| Métrica | Definição |
|---------|-----------|
| `total_customers` | Clientes cadastrados até o fim do período (`created_at` anterior ao fim do dia `to` local) e não excluídos até esse instante. Um cliente excluído depois do fim continua contando naquele período. Para um período que termina hoje, igual a `meta.total` de `GET /customers` |
| `active_customers` | Clientes distintos com ao menos uma transação `paid` no período — o mesmo valor de `customers` no `/dashboard` |
| `new_customers` | Clientes ativos cuja **primeira transação `paid` de todo o histórico** ocorreu no período (independe da data de cadastro) |
| `returning_customers` | Clientes ativos que já tinham transação `paid` antes do início do período |

`new_customers + returning_customers = active_customers` (em `value` e em `previous`). Transações `pending`, `refunded` e `canceled` não geram atividade nem contam como primeira compra.

**Ranking** — clientes com ao menos uma transação `paid` no período; `revenue` = `SUM(total_amount)` e `orders` = quantidade de transações pagas (mesmas definições do Dashboard e de `total_spent` / `orders_count` em `GET /customers/{id}`). Ordenação: `sort=revenue` → receita, pedidos, nome, id; `sort=orders` → pedidos, receita, nome, id.

- **Soft delete:** o histórico não some. Compras de clientes excluídos continuam em `active`, `new`, `returning` e no ranking (`is_deleted: true`); a exclusão só tira o cliente de `total_customers` a partir do instante em que ocorreu.
- **Timezone:** as fronteiras (atividade, primeira compra, cadastro e exclusão) são os limites dos dias no calendário da Organization, calculados na aplicação e convertidos para UTC (DST exato também no SQLite). Ex.: uma primeira compra em `2026-09-01T02:30:00Z` é de agosto em `America/Sao_Paulo` (cliente recorrente em setembro) e de setembro em `Europe/Lisbon` (cliente novo).

```http
GET /api/v1/analytics/customers?from=2026-09-01&to=2026-09-30&sort=revenue&limit=2
X-Organization-Id: {organization-uuid}
Accept: application/json
```

```json
{
  "data": [
    {
      "rank": 1,
      "customer": { "id": "9d1b…", "name": "Maria Souza", "email": "maria@example.com", "is_deleted": false },
      "revenue": { "value": "1250.00", "previous": "1000.00", "change": 25.0 },
      "orders": { "value": 2, "previous": 1, "change": 100.0 }
    },
    {
      "rank": 2,
      "customer": { "id": "9d1c…", "name": "João Lima", "email": "joao@example.com", "is_deleted": true },
      "revenue": { "value": "480.00", "previous": "0.00", "change": null },
      "orders": { "value": 1, "previous": 0, "change": null }
    }
  ],
  "summary": {
    "total_customers": { "value": 72, "previous": 70, "change": 2.9 },
    "active_customers": { "value": 52, "previous": 47, "change": 10.6 },
    "new_customers": { "value": 6, "previous": 9, "change": -33.3 },
    "returning_customers": { "value": 46, "previous": 38, "change": 21.1 }
  },
  "meta": {
    "period": { "from": "2026-09-01", "to": "2026-09-30", "days": 30 },
    "previous_period": { "from": "2026-08-02", "to": "2026-08-31", "days": 30 },
    "timezone": "America/Sao_Paulo",
    "currency": "BRL",
    "sort": "revenue",
    "limit": 2
  }
}
```

Sem clientes ou sem vendas: `"data": []` e contadores `{ "value": 0, "previous": 0, "change": 0.0 }` (`total_customers` conta os cadastrados mesmo sem compras).

Quatro consultas agregadas por request (ranking com os dados do cliente via join, primeira compra/novos/recorrentes, base de clientes e KPIs do Dashboard), independentemente do número de clientes ou de `limit` — sem N+1. A consulta de primeira compra percorre o histórico pago da organização até o fim do período. Limitações: as mesmas do Dashboard (status atual, dia de hoje parcial). O seed de demonstração não tem clientes excluídos e cadastra todos os clientes antes do histórico de vendas, então nele `total_customers` fica estável; essas regras são cobertas por testes com dados controlados.

### Transaction status analytics

`GET /api/v1/analytics/transactions` devolve a distribuição das transações do período por status, comparada ao período anterior. Aceita apenas `from` / `to` (mesmas regras do Dashboard: juntos, máx. 366 dias, padrão últimos 30 dias, timezone da Organization).

Este endpoint é a **exceção documentada à regra de venda `paid`**: conta transações de todos os status. Cada item de `data` traz `status` e três métricas no formato `{ value, previous, change }`:

| Métrica | Definição |
|---------|-----------|
| `orders` | Quantidade de transações do status no período (transações, não itens) |
| `revenue` | `SUM(total_amount)` das transações do status, em string com 2 casas. Só a linha `paid` é receita no sentido do Dashboard; nas demais é o valor movimentado naquele status |
| `percentage` | Participação do status no total de transações do período, com 1 casa decimal (float). Sem transações no período → `null`. `change` é a variação relativa entre os percentuais exibidos (65.3 vs 61.8 → 5.7), `null` se algum lado é `null` ou o anterior é zero |

- **Ordem fixa e completa:** sempre os 4 status, na ordem `paid`, `refunded`, `pending`, `canceled`; status sem transações aparece zerado.
- **Consistência:** a linha `paid` tem `orders` e `revenue` idênticos ao `/dashboard` do mesmo período; a soma de `orders` dos 4 status é igual a `meta.total` de `GET /transactions?from&to`. Por arredondamento, a soma dos percentuais pode ficar em 100.0 ± 0.2.
- **Status atual:** a transação conta no status que tem hoje e na data de `occurred_at` (um reembolso de uma venda antiga continua no período da venda original).

```http
GET /api/v1/analytics/transactions?from=2026-09-01&to=2026-09-30
X-Organization-Id: {organization-uuid}
Accept: application/json
```

```json
{
  "data": [
    {
      "status": "paid",
      "orders": { "value": 2, "previous": 1, "change": 100.0 },
      "revenue": { "value": "150.00", "previous": "100.00", "change": 50.0 },
      "percentage": { "value": 50.0, "previous": 50.0, "change": 0.0 }
    },
    {
      "status": "refunded",
      "orders": { "value": 1, "previous": 0, "change": null },
      "revenue": { "value": "30.00", "previous": "0.00", "change": null },
      "percentage": { "value": 25.0, "previous": 0.0, "change": null }
    },
    {
      "status": "pending",
      "orders": { "value": 1, "previous": 0, "change": null },
      "revenue": { "value": "20.00", "previous": "0.00", "change": null },
      "percentage": { "value": 25.0, "previous": 0.0, "change": null }
    },
    {
      "status": "canceled",
      "orders": { "value": 0, "previous": 1, "change": -100.0 },
      "revenue": { "value": "0.00", "previous": "40.00", "change": -100.0 },
      "percentage": { "value": 0.0, "previous": 50.0, "change": -100.0 }
    }
  ],
  "meta": {
    "period": { "from": "2026-09-01", "to": "2026-09-30", "days": 30 },
    "previous_period": { "from": "2026-08-02", "to": "2026-08-31", "days": 30 },
    "timezone": "America/Sao_Paulo",
    "currency": "BRL"
  }
}
```

Sem transações em um período: os 4 status com `orders` 0, `revenue` `"0.00"` e `percentage` `null` naquele período.

Uma única consulta agregada por request (agrupada por status, período anterior via `CASE`), sem acessar itens nem clientes. Fronteiras calculadas na aplicação (sem buckets): SQLite e PostgreSQL idênticos, DST incluído.

## Variáveis de ambiente

| Arquivo | Uso |
|---------|-----|
| `apps/api/.env.example` | API, Postgres, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `TRUSTED_PROXIES` |
| `apps/web/.env.example` | `NUXT_PUBLIC_API_URL`, `NUXT_PUBLIC_API_ORIGIN` |

Não commite arquivos `.env` com secrets.

## Produção

Destino planejado: API no **Railway** (com PostgreSQL do Railway) e front estático no **Cloudflare Pages**, ambos com deploy pela integração do GitHub.

### Requisito de domínio

A autenticação é Sanctum SPA: sessão em cookie httpOnly `SameSite=Lax` e CSRF pelo cookie `XSRF-TOKEN`, que o front lê via `document.cookie` e devolve no header `X-XSRF-TOKEN`. Isso só funciona se front e API estiverem no **mesmo site** (mesmo domínio registrável), por exemplo `app.seudominio.com` e `api.seudominio.com` com `SESSION_DOMAIN=.seudominio.com`.

As URLs padrão (`*.pages.dev` e `*.up.railway.app`) são sites diferentes — os dois sufixos estão na Public Suffix List. Nelas o navegador não envia o cookie de sessão nas chamadas da API e o front não consegue ler o `XSRF-TOKEN`, então **o login não funciona sem um domínio próprio** (ou sem um proxy que coloque a API na mesma origem do front).

### API no Railway

Serviço a partir deste repositório, com **Root Directory** `apps/api`. O builder padrão (Railpack) detecta o Laravel, instala PHP 8.4 e `pdo_pgsql` a partir do `composer.json`, e a cada start roda `storage:link` e `optimize` (cache de config, rotas, eventos e views) antes de subir o FrankenPHP. Por padrão ele também roda `migrate --force` a cada start; com `RAILPACK_SKIP_MIGRATIONS=true` as migrations passam para o pre-deploy (uma vez por deploy, antes de o tráfego mudar).

Configurações do serviço:

- Pre-deploy command: `php artisan migrate --force && php artisan pulseboard:demo` (migrations + conta de demonstração; ver abaixo).
- Healthcheck path: `/api/v1/health` (200 com `{"status":"ok","database":"ok"}`, 503 se o banco não responde; não expõe versões nem erros).
- Watch paths: `/apps/api/**` (evita redeploy da API em mudanças só do front).
- Domínio: `api.seudominio.com` (custom domain do Railway, HTTPS automático).

Variáveis:

| Variável | Valor |
|----------|-------|
| `APP_NAME` | `PulseBoard` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | gerar localmente com `php artisan key:generate --show` |
| `APP_URL` | `https://api.seudominio.com` |
| `FRONTEND_URL` | `https://app.seudominio.com` (origem exata liberada no CORS) |
| `SANCTUM_STATEFUL_DOMAINS` | `app.seudominio.com` (host do front, sem esquema) |
| `SESSION_DOMAIN` | `.seudominio.com` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_SAME_SITE` | `lax` |
| `SESSION_DRIVER` / `CACHE_STORE` | `database` (tabelas criadas pelas migrations; sem Redis) |
| `TRUSTED_PROXIES` | `*` (a API só é acessível pelo proxy do Railway; sem isso o rate limit de login trata todos os usuários como o mesmo IP) |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` (referência ao serviço PostgreSQL do Railway) |
| `LOG_CHANNEL` | `stderr` (logs aparecem no painel do Railway) |
| `LOG_LEVEL` | `info` |
| `RAILPACK_SKIP_MIGRATIONS` | `true` (migrations no pre-deploy) |
| `DEMO_PASSWORD` | senha das contas de demonstração (mínimo 12 caracteres; é a senha que você divulga no portfólio) |
| `DEMO_OWNER_EMAIL` / `DEMO_MEMBER_EMAIL` | opcionais; padrão `demo@example.com` e `demo-member@example.com` |

Não há filas, jobs, e-mails nem storage de arquivos em uso: não é preciso worker, Redis ou volume.

### Dados de demonstração

`php artisan pulseboard:demo` cria a organização "PulseBoard Demo Store" (slug `pulseboard-demo`) com uma conta owner e uma member, 40 produtos, 70 clientes e ~90 dias de vendas terminando hoje. Não usa Faker nem factories, e só mexe nessa organização:

- sem `DEMO_PASSWORD` (ou com menos de 12 caracteres) o comando falha sem gravar nada;
- se a demo já existe, não altera os dados, só sincroniza a senha das duas contas com `DEMO_PASSWORD` — por isso pode rodar em todo deploy;
- `--refresh` apaga produtos, clientes e transações **somente da organização de demo** (inclusive o que visitantes criaram) e gera o histórico de novo, terminando no dia atual;
- recusa rodar se um dos e-mails de demo pertence a uma conta de outra organização, ou se o slug pertence a uma organização que não é da conta demo owner.

O histórico é relativo à data do seed: com o período padrão de 30 dias, o dashboard esvazia cerca de um mês depois. Rode `php artisan pulseboard:demo --refresh` periodicamente (manualmente no serviço, ou num serviço de cron do Railway com esse comando como start command).

O `DatabaseSeeder` (`migrate:fresh --seed`) continua sendo só para desenvolvimento: usa factories e cria `test@example.com` / `password`.

### Front no Cloudflare Pages

Projeto conectado ao repositório:

| Configuração | Valor |
|--------------|-------|
| Root directory | `apps/web` |
| Build command | `bun install --frozen-lockfile && bun run generate` |
| Build output directory | `dist` |
| Variáveis de build | `NUXT_PUBLIC_API_URL=https://api.seudominio.com/api/v1`, `NUXT_PUBLIC_API_ORIGIN=https://api.seudominio.com`, `BUN_VERSION=1.3.10` |
| Domínio | `app.seudominio.com` |

No Pages (`CF_PAGES`), o Nitro usa o preset `cloudflare-pages-static`: gera `dist/`, headers de cache imutável para `/_nuxt/*` e mescla o `public/_headers` (anti-clickjacking). As URLs da API são embutidas no HTML durante o build; se as duas variáveis faltarem, o build falha de propósito. Não há `404.html`, então o Pages usa o fallback de SPA e rotas como `/products/:id` abrem direto.

### Checklist do primeiro deploy

1. Domínio próprio com dois subdomínios (front e API).
2. Railway: PostgreSQL + serviço da API com as variáveis acima e o custom domain.
3. Conferir `https://api.seudominio.com/api/v1/health` e, nos logs do pre-deploy, a linha "Demo organization seeded".
4. Cloudflare Pages com as variáveis de build e o custom domain.
5. Login no front com `DEMO_OWNER_EMAIL` / `DEMO_PASSWORD`, criar/editar/remover um produto e navegar pelo analytics.

## Limitações conhecidas

- Sem domínio próprio compartilhado entre front e API, a autenticação não funciona em produção (ver acima). Previews do Pages (`<hash>.<projeto>.pages.dev`) também não autenticam: não estão no CORS nem no `SANCTUM_STATEFUL_DOMAINS`.
- Um único ambiente de produção; sem staging.
- A conta demo owner pode alterar os dados da demo (é o objetivo); `--refresh` restaura o estado original.
- Railpack instala as dependências de desenvolvimento do Composer (não usa `--no-dev`); o runtime não depende delas.
- Sem monitoramento de erros externo: os logs são o `stderr` do Railway.
- Suíte PHPUnit local em SQLite por padrão; a paridade com PostgreSQL é garantida na CI.

## Status do projeto

- [x] Fase 1 — Foundation  
- [x] Fase 2 — Authentication  
- [x] Fase 3 — Core domain  
- [x] Fase 4 — API de negócio: 4A Products + Customers, 4B Transactions (read-only) e 4C Analytics concluídas (4C.1 timezone + fundação, 4C.2 Dashboard, 4C.3 Revenue, 4C.4 Products, 4C.5 Customers, 4C.6 Transaction status, 4C.7 consistência + performance + docs)  
- [x] Frontend (F1–F10): auth, shell, CRUDs, transações, dashboard, analytics, acessibilidade e smoke E2E  
- [x] CI (GitHub Actions: API em PostgreSQL, web, E2E)  
- [x] Produção preparada (F11): configuração e documentação de deploy  
- [ ] Deploy real (depende do domínio e das contas Railway/Cloudflare)  
