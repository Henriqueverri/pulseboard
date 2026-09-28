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
- [ ] Fase 4 — API de negócio (4A Products + Customers concluída; 4B Transactions/Dashboard pendente)  
- [ ] Fase 5 — Frontend de produto  
- [ ] Fase 6 — Tests  
- [ ] Fase 7 — CI/CD  
- [ ] Fase 8 — Production  
