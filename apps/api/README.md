# PulseBoard API

API REST em Laravel 12 (PHP 8.4) com duas superfícies: a interna, para o front, com autenticação Sanctum SPA (cookie de sessão + CSRF) e multi-tenancy por Organization (`X-Organization-Id` + membership); e a de ingestão (`/api/v1/ingest/*`), para sistemas externos, autenticada por API Key. PostgreSQL.

Visão geral e setup local no [README da raiz](../../README.md). Guia de integração em [`docs/integration.md`](../../docs/integration.md), contratos dos endpoints em [`docs/api.md`](../../docs/api.md), decisões em [`docs/architecture.md`](../../docs/architecture.md), testes em [`docs/testing.md`](../../docs/testing.md) e deploy em [`docs/deployment.md`](../../docs/deployment.md).

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
| `app/Http/Controllers/Api` | Controllers finos (auth, CRUD, analytics, API Keys) |
| `app/Http/Controllers/Api/Ingest` | API de ingestão: criação, reconciliação e mudanças de status |
| `app/Http/Middleware/EnsureOrganizationContext.php` | Resolve o tenant do header e valida a membership |
| `app/Http/Middleware/AuthenticateApiKey.php` | Autentica a ingestão pela API Key e registra a organização da chave |
| `app/Http/Middleware/AssignRequestId.php` | `X-Request-Id` em toda resposta e no contexto dos logs |
| `app/Http/Requests`, `app/Http/Resources` | Validação e formato das respostas |
| `app/Policies` | Autorização por papel (owner/member) dentro da organização |
| `app/Services` | Métricas e analytics (agregações em SQL) |
| `app/Services/Ingestion`, `app/Services/TransactionLifecycle.php` | Ingestão idempotente (insert-first + fingerprint) e a única porta de mudança de status |
| `app/Enums/TransactionStatus.php` | Máquina de estados das transações |
| `app/Support/ApiKeyGenerator.php` | Formato `pb_<prefix>_<secret>`, hash SHA-256 e verificação |
| `app/Support/Analytics` | Período, comparação, granularidade, buckets por timezone |
| `bootstrap/app.php` | Middleware, rate limit e erros em JSON para `api/*` (com `code` na ingestão) |
| `config/trustedproxy.php` | `TRUSTED_PROXIES` para rodar atrás do proxy do Render |
| `app/Console/Commands` | `pulseboard:demo` (organização de demonstração), `pulseboard:release` (start do container), `pulseboard:ai-eval` (avaliação de IA), `pulseboard:ai-prune` (retenção de IA) e `pulseboard:ai-usage` (uso e custo de IA) |
| `Dockerfile`, `docker/` | Imagem de produção: Nginx + PHP-FPM, entrypoint, configs de PHP |

Health check: `GET /api/v1/health` (banco) e `GET /up` (padrão do Laravel, sem banco).

## Imagem de produção

```bash
docker build -t pulseboard-api .
docker run --rm -p 10000:10000 --env-file prod.env pulseboard-api   # prod.env fora do Git
```

Alpine com PHP 8.4-FPM (`pdo_pgsql`, OPcache sem revalidação), Nginx e dependências `--no-dev`. Roda como `www-data`; nada de `.env` na imagem, toda a configuração vem de variáveis de ambiente. O entrypoint (`docker/entrypoint.sh`):

1. valida `PORT` e `APP_KEY` e garante que `storage/` e `bootstrap/cache/` são graváveis;
2. `php artisan optimize` (cache de config, rotas, eventos e views, com as variáveis do runtime);
3. `php artisan pulseboard:release`: `migrate --force`, `pulseboard:demo` e `pulseboard:ai-prune`, sob um advisory lock do PostgreSQL (dois containers subindo juntos rodam um depois do outro);
4. sobe PHP-FPM (`127.0.0.1:9000`) e Nginx (porta `$PORT`), e encerra o container se qualquer um cair.

Se algum passo falhar (por exemplo `DEMO_PASSWORD` ausente ou curta, ou banco inacessível), o container sai com erro e o Render mantém o deploy anterior no ar. Detalhes do deploy em [`docs/deployment.md`](../../docs/deployment.md).
