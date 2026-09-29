# PulseBoard API

API REST em Laravel 12 (PHP 8.4) com autenticação Sanctum SPA (cookie de sessão + CSRF), multi-tenancy por Organization (`X-Organization-Id` + membership) e PostgreSQL.

Setup local, contratos dos endpoints, testes, CI e deploy estão no [README da raiz](../README.md).

## Comandos

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed   # banco local com dataset de demonstração
php artisan serve --port=8000
php artisan test                   # SQLite em memória
vendor/bin/pint --test
```

## Organização do código

| Pasta | Conteúdo |
|-------|----------|
| `app/Http/Controllers/Api` | Controllers finos (auth, CRUD, analytics) |
| `app/Http/Middleware/EnsureOrganizationContext.php` | Resolve o tenant do header e valida a membership |
| `app/Http/Requests`, `app/Http/Resources` | Validação e formato das respostas |
| `app/Policies` | Autorização por papel (owner/member) dentro da organização |
| `app/Services` | Métricas e analytics (agregações em SQL) |
| `app/Support/Analytics` | Período, comparação, granularidade, buckets por timezone |
| `bootstrap/app.php` | Middleware, erros em JSON para `api/*` |
| `config/trustedproxy.php` | `TRUSTED_PROXIES` para rodar atrás do proxy do Railway |

Health check: `GET /api/v1/health` (banco) e `GET /up` (padrão do Laravel, sem banco).
