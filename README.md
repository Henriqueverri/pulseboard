# PulseBoard

SaaS de analytics para pequenos negócios acompanharem vendas, receita, clientes e indicadores em um dashboard.

Este repositório é um monorepo de portfólio full stack. A **Fase 1 (Foundation)** entrega a estrutura local; autenticação, domínio e dashboard virão nas fases seguintes.

## Stack

| Camada | Tecnologia |
|--------|------------|
| Frontend | Nuxt 4, Vue 3, TypeScript, Tailwind CSS |
| Backend | Laravel 12, PHP 8.2+, API REST |
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

Não há packages compartilhados nesta fase.

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

Resposta esperada:

```json
{
  "status": "ok",
  "app": "PulseBoard",
  "database": "ok"
}
```

### 3. Frontend (Nuxt)

```bash
cd apps/web
cp .env.example .env   # se ainda não existir
bun install
bun run dev
```

- Frontend: http://localhost:3000  

A página inicial consulta o health da API usando `NUXT_PUBLIC_API_URL`.

## Variáveis de ambiente

| Arquivo | Uso |
|---------|-----|
| `apps/api/.env.example` | Template da API (Postgres, `FRONTEND_URL`, CORS) |
| `apps/web/.env.example` | Template do front (`NUXT_PUBLIC_API_URL`, `NUXT_PUBLIC_API_ORIGIN`) |

Não commite arquivos `.env` com secrets.

## CORS (preparação)

A API permite origem `FRONTEND_URL` (default `http://localhost:3000`) com `supports_credentials=true`, alinhado à autenticação Sanctum SPA prevista nas próximas fases.

## Status do projeto

- [x] Fase 1 — Foundation  
- [ ] Fase 2 — Authentication  
- [ ] Fase 3 — Core domain  
- [ ] Fase 4 — API de negócio  
- [ ] Fase 5 — Frontend de produto  
- [ ] Fase 6 — Tests  
- [ ] Fase 7 — CI/CD  
- [ ] Fase 8 — Production  
