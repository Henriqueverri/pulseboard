# PulseBoard

SaaS de analytics para pequenos negócios acompanharem vendas, receita, clientes e indicadores em um dashboard.

Este repositório é um monorepo de portfólio full stack. A **Fase 2 (Auth + Organization)** entrega autenticação Sanctum SPA (cookie) e multi-tenancy por Organization.

## Stack

| Camada | Tecnologia |
|--------|------------|
| Frontend | Nuxt 4, Vue 3, TypeScript, Tailwind CSS, Pinia |
| Backend | Laravel 12, PHP 8.2+, API REST, Sanctum SPA |
| Banco | PostgreSQL 16 |
| Package FE | bun |
| Infra local | Docker Compose (somente Postgres no MVP) |

## Estrutura do monorepo

```text
pulseboard/
├── apps/
│   ├── web/          # Nuxt 4
│   └── api/          # Laravel 12
├── .github/
├── docker-compose.yml
├── README.md
└── .gitignore
```

## Pré-requisitos

- PHP 8.2+ com extensões `pdo_pgsql` / `pgsql`
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

Ordenação: `occurred_at` desc, depois `id` desc. A listagem traz `customer` e `items_count`; o detalhe traz `customer` e `items` com `product`. Customers/products soft-deleted continuam aparecendo no histórico com `is_deleted: true`. `unit_price` é o preço no momento da venda.

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

Os KPIs saem de uma única consulta agregada no banco. Limitações conhecidas: o status é o estado atual da transação (um estorno remove a receita da data original da venda) e o período padrão inclui o dia de hoje ainda em andamento.

## Variáveis de ambiente

| Arquivo | Uso |
|---------|-----|
| `apps/api/.env.example` | API, Postgres, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` |
| `apps/web/.env.example` | `NUXT_PUBLIC_API_URL`, `NUXT_PUBLIC_API_ORIGIN` |

Não commite arquivos `.env` com secrets.

## Status do projeto

- [x] Fase 1 — Foundation  
- [x] Fase 2 — Authentication  
- [x] Fase 3 — Core domain  
- [ ] Fase 4 — API de negócio (4A Products + Customers, 4B Transactions, 4C.1 timezone + fundação de analytics e 4C.2 Dashboard KPIs concluídas; Analytics detalhado pendente)  
- [ ] Fase 5 — Frontend de produto  
- [ ] Fase 6 — Tests  
- [ ] Fase 7 — CI/CD  
- [ ] Fase 8 — Production  
