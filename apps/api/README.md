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
| `config/trustedproxy.php` | `TRUSTED_PROXIES` para rodar atrás do proxy do Render |
| `app/Console/Commands` | `pulseboard:demo` (organização de demonstração) e `pulseboard:release` (start do container) |
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
3. `php artisan pulseboard:release`: `migrate --force` e `pulseboard:demo`, sob um advisory lock do PostgreSQL (dois containers subindo juntos rodam um depois do outro);
4. sobe PHP-FPM (`127.0.0.1:9000`) e Nginx (porta `$PORT`), e encerra o container se qualquer um cair.

Se algum passo falhar (por exemplo `DEMO_PASSWORD` ausente ou curta, ou banco inacessível), o container sai com erro e o Render mantém o deploy anterior no ar. Detalhes do deploy no [README da raiz](../README.md#produção).
