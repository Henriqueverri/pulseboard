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
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

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

## Variáveis de ambiente

| Arquivo | Uso |
|---------|-----|
| `apps/api/.env.example` | API, Postgres, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS` |
| `apps/web/.env.example` | `NUXT_PUBLIC_API_URL`, `NUXT_PUBLIC_API_ORIGIN` |

Não commite arquivos `.env` com secrets.

## Status do projeto

- [x] Fase 1 — Foundation  
- [x] Fase 2 — Authentication  
- [ ] Fase 3 — Core domain  
- [ ] Fase 4 — API de negócio  
- [ ] Fase 5 — Frontend de produto  
- [ ] Fase 6 — Tests  
- [ ] Fase 7 — CI/CD  
- [ ] Fase 8 — Production  
