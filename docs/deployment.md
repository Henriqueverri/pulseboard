# Deploy e operação

Como o PulseBoard roda em produção e como operar a demo. Visão geral no [README](../README.md).

## Topologia

```text
Cloudflare Pages (SPA estática)          app.henriqueverri.dev
        │  fetch com cookies (Sanctum SPA)
        ▼
Render Web Service (Free, Docker)        api.henriqueverri.dev
  Nginx + PHP-FPM
        │  Supabase Shared Pooler, Session mode (5432), TLS
        ▼
Supabase PostgreSQL
```

O banco não fica no Render: a API usa o PostgreSQL do Supabase. Não há Redis, worker, fila nem cron.

| Peça | Como publica |
|------|--------------|
| Frontend | Integração Git do Cloudflare Pages: build a cada push na `main` |
| API | Blueprint do Render ([`render.yaml`](../render.yaml)): deploy depois que os checks da CI passam e só quando `apps/api/**` muda |
| Banco | Supabase gerenciado; migrations aplicadas pela API no start do container |

## Requisito de domínio

A autenticação é Sanctum SPA: sessão em cookie httpOnly `SameSite=Lax` e CSRF pelo cookie `XSRF-TOKEN`, que o front lê via `document.cookie` e devolve no header `X-XSRF-TOKEN`. Isso só funciona se front e API estiverem no **mesmo site** (mesmo domínio registrável): `app.henriqueverri.dev` e `api.henriqueverri.dev` com `SESSION_DOMAIN=.henriqueverri.dev`.

As URLs padrão das plataformas (`*.onrender.com`, `*.pages.dev`) são sites diferentes — os sufixos estão na Public Suffix List. Nelas o navegador não envia o cookie de sessão nas chamadas da API e o front não consegue ler o `XSRF-TOKEN`, então **o login não funciona sem o domínio próprio**. Previews do Pages (`<hash>.<projeto>.pages.dev`) também não autenticam: não estão no CORS nem no `SANCTUM_STATEFUL_DOMAINS`.

## API no Render

Web Service **Free** com runtime **Docker**, definido em [`render.yaml`](../render.yaml) (Blueprint):

| Configuração | Valor |
|--------------|-------|
| Dockerfile | `./apps/api/Dockerfile` (caminho relativo à raiz do repositório) |
| Docker build context | `./apps/api` |
| Branch / auto deploy | `main`, deploy automático quando os checks da CI passam (`autoDeployTrigger: checksPass`) |
| Build filter | `apps/api/**` (mudanças só no front não redeployam a API; testes e `.md` ignorados) |
| Health check | `/api/v1/health` (200 com `{"status":"ok","database":"ok"}`, 503 se o banco não responde) |
| Domínio | `api.henriqueverri.dev` (custom domain, HTTPS automático) |

Sem usar o Blueprint, os mesmos valores vão no Dashboard: *New → Web Service*, runtime Docker, deixe **Root Directory vazio** e preencha *Dockerfile Path* `./apps/api/Dockerfile` e *Docker Build Context Directory* `./apps/api`; em *Build Filters*, inclua `apps/api/**`.

O Render Free não tem Pre-Deploy Command nem Shell, então migrations e demo rodam no start do container ([`apps/api/docker/entrypoint.sh`](../apps/api/docker/entrypoint.sh)):

1. `php artisan optimize` — cache de config, rotas, eventos e views, gerado no runtime (as variáveis não existem no build);
2. `php artisan pulseboard:release` — `migrate --force`, `pulseboard:demo` e `pulseboard:ai-prune` (retenção dos dados de IA, ver [Insights](#insights-custo-cotas-e-retenção)), segurando um advisory lock do PostgreSQL: se dois containers sobem ao mesmo tempo (restart durante um deploy), o segundo espera e encontra tudo pronto;
3. PHP-FPM + Nginx na porta `$PORT` (o Render define; padrão 10000).

Qualquer falha nesses passos (banco inacessível, migration quebrada, `DEMO_PASSWORD` ausente ou curta, retenção de IA inválida) derruba o container antes de ele aceitar tráfego; num deploy, o Render mantém a versão anterior no ar. O Free hiberna o serviço após ~15 min sem requisições: o primeiro acesso depois disso espera o container subir de novo (e repetir os passos acima, que são idempotentes).

A imagem (Alpine, PHP 8.4-FPM com OPcache, Nginx, dependências `--no-dev`, usuário `www-data`, nenhum `.env` embutido) está descrita no [README da API](../apps/api/README.md#imagem-de-produção).

## Banco no Supabase

Use a connection string do **Shared Pooler em Session mode** (*Connect → Session pooler* no painel do Supabase), porta **5432**:

```text
postgresql://postgres.<project-ref>:<senha-url-encoded>@aws-0-<região>.pooler.supabase.com:5432/postgres
```

- **Não** use a conexão direta (`db.<ref>.supabase.co`): ela é só IPv6 e o Render não tem saída IPv6.
- **Não** use o Transaction mode (porta 6543): o PDO usa prepared statements no servidor e o `pulseboard:release` usa advisory lock de sessão; os dois exigem Session mode.
- Caracteres especiais da senha precisam de URL-encoding (`@` → `%40`, `:` → `%3A`, `/` → `%2F`).
- `DB_SSLMODE=require` força TLS na conexão.
- O pool do Shared Pooler é pequeno; o PHP-FPM usa no máximo 5 workers (uma conexão cada).
- No plano Free do Supabase o projeto pausa após alguns dias sem atividade; com o banco pausado o health check responde 503 e o deploy falha até reativá-lo no painel.

## Variáveis de ambiente

Nenhum valor real vai para o Git. No `render.yaml`, os secrets (`APP_KEY`, `DB_URL`, `DEMO_PASSWORD`, `OPENAI_API_KEY`) usam `sync: false`: o Render pede os valores ao criar o Blueprint.

| Variável | Valor |
|----------|-------|
| `APP_NAME` | `PulseBoard` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | secret — gerar localmente com `php artisan key:generate --show` |
| `APP_URL` | `https://api.henriqueverri.dev` |
| `FRONTEND_URL` | `https://app.henriqueverri.dev` (origem exata liberada no CORS) |
| `SANCTUM_STATEFUL_DOMAINS` | `app.henriqueverri.dev` (host do front, sem esquema) |
| `SESSION_DRIVER` | `database` |
| `SESSION_DOMAIN` | `.henriqueverri.dev` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_SAME_SITE` | `lax` |
| `CACHE_STORE` | `database` (tabelas criadas pelas migrations; sem Redis) |
| `QUEUE_CONNECTION` | `database` (nenhum job é despachado; sem worker) |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | secret — connection string do Session pooler (ver acima) |
| `DB_SSLMODE` | `require` |
| `TRUSTED_PROXIES` | `*` (a API só é acessível pelo proxy do Render; sem isso as requisições parecem HTTP e o rate limit de login trata todos os usuários como o mesmo IP) |
| `LOG_CHANNEL` | `stderr` (logs aparecem no painel do Render) |
| `LOG_LEVEL` | `info` |
| `LOG_STDERR_FORMATTER` | `Monolog\Formatter\JsonFormatter` (um objeto JSON por linha, ver [Logs](#logs)) |
| `DEMO_PASSWORD` | secret — senha das contas de demonstração (mínimo 12 caracteres) |
| `DEMO_OWNER_EMAIL` / `DEMO_MEMBER_EMAIL` | `demo@example.com` / `demo-member@example.com` (padrão) |
| `AI_ENABLED` | `false` — kill switch global dos Insights (ver [Insights](#insights-custo-cotas-e-retenção)) |
| `AI_PROVIDER` / `AI_MODEL` | `openai` / `gpt-6-luna` |
| `OPENAI_API_KEY` | secret — só no painel do Render, nunca no Git nem no front |
| `AI_MONTHLY_BUDGET_USD` | `2` — teto global do custo estimado no mês (UTC) |
| `AI_DEMO_DAILY_IP_LIMIT` | `5` — gerações por IP por dia na organização de demo |

`PORT` é definida pelo próprio Render. Localmente, os modelos são [`apps/api/.env.example`](../apps/api/.env.example) e [`apps/web/.env.example`](../apps/web/.env.example).

Nenhuma API Key de integração é configurada no deploy: elas são criadas pelos owners na UI e só o hash fica no banco.

## Logs

Com `LOG_STDERR_FORMATTER`, cada linha no painel do Render é um objeto JSON, filtrável pela busca de logs. Toda linha de uma requisição em `api/*` carrega o `request_id`, que é o mesmo valor do header `X-Request-Id` devolvido ao cliente. A ingestão grava uma linha por requisição, como esta, capturada localmente com a mesma configuração (IDs encurtados; em produção, `channel` é `production`):

```json
{"message":"Transaction ingestion completed.","context":{"request_id":"a0217562-…","event":"ingest.transaction","outcome":"created","organization_id":"01a1099b-…","api_key_id":"01a1099c-…","api_key_prefix":"9ZHIXrxjnJ8a","external_id":"e2e_order_1791162587668","transaction_id":"8392c213-…","items_count":1,"http_status":201,"code":null,"duration_ms":24.03},"level":200,"level_name":"INFO","channel":"local","datetime":"2026-10-05T01:09:52.133628+00:00","extra":{}}
```

| `event` | Quando | Campos úteis |
|---------|--------|--------------|
| `ingest.transaction` | Cada `POST /ingest/transactions` | `outcome` (`created`, `replayed`, `conflict`, `rejected`, `failed`), `code`, `external_id`, `api_key_prefix`, `duration_ms` |
| `ingest.status_change` | Cada mudança de status | `outcome` (`transitioned`, `replayed`, `conflict`, `not_found`, `rejected`, `failed`), `requested_status`, `code` |
| `ingest.auth_failed` | Chave recusada (`warning`) | `reason` (`missing`, `malformed`, `unknown`, `wrong_secret`, `revoked`, `expired`), `api_key_prefix` quando parseável |

Para investigar um problema de integração, peça o `X-Request-Id` ou o `external_id` ao integrador e busque por ele nos logs; a transação também aparece na busca da tela de Transações pelo `external_id`, com a linha do tempo de status. A chave, o header `Authorization`, o hash e os dados pessoais do cliente nunca são logados.

Mudanças nas variáveis do `render.yaml` valem no próximo sync do Blueprint; num serviço criado sem Blueprint, adicione `LOG_STDERR_FORMATTER` em *Environment* no painel.

## Front no Cloudflare Pages

Projeto conectado ao repositório:

| Configuração | Valor |
|--------------|-------|
| Root directory | `apps/web` |
| Build command | `bun install --frozen-lockfile && bun run generate` |
| Build output directory | `dist` |
| Variáveis de build | `NUXT_PUBLIC_API_URL=https://api.henriqueverri.dev/api/v1`, `NUXT_PUBLIC_API_ORIGIN=https://api.henriqueverri.dev`, `BUN_VERSION=1.3.10` |
| Domínio | `app.henriqueverri.dev` |

No Pages (`CF_PAGES`), o Nitro usa o preset `cloudflare-pages-static`: gera `dist/`, headers de cache imutável para `/_nuxt/*` e mescla o `public/_headers` (anti-clickjacking). As URLs da API são embutidas no HTML durante o build; se as duas variáveis faltarem, o build falha de propósito. Não há `404.html`, então o Pages usa o fallback de SPA e rotas como `/products/:id` abrem direto.

## Dados de demonstração

`php artisan pulseboard:demo` cria a organização "PulseBoard Demo Store" (slug `pulseboard-demo`) com uma conta owner e uma member, 40 produtos, 70 clientes e ~6 meses (180 dias) de vendas terminando hoje, com dias e horários no fuso da organização. Não usa Faker nem factories, e só mexe nessa organização:

- sem `DEMO_PASSWORD` (ou com menos de 12 caracteres) o comando falha sem gravar nada;
- se a demo já existe, mantém os dados, sincroniza a senha das duas contas com `DEMO_PASSWORD` e **completa o histórico até hoje**: gera vendas só para os dias depois da última venda, a partir do catálogo ativo da demo e dos clientes não excluídos, com os preços atuais. Cada dia é gerado a partir de uma semente própria (slug + data), então é reproduzível, e rodar de novo no mesmo dia não adiciona nada;
- `--refresh` apaga API Keys, produtos, clientes e transações **somente da organização de demo** (inclusive o que visitantes criaram ou ingeriram) e gera o histórico de novo, terminando no dia atual;
- recusa rodar se um dos e-mails de demo pertence a uma conta de outra organização, ou se o slug pertence a uma organização que não é da conta demo owner.

Em produção ele roda a cada start do container (via `pulseboard:release`), sem `--refresh`. Como o Render Free hiberna após ~15 min sem tráfego, praticamente toda primeira visita sobe o container e completa os dias que faltam — o período padrão do dashboard continua com dados e comparações sem intervenção manual.

Lacunas maiores que 180 dias são preenchidas só nos últimos 180 dias. Para voltar ao estado original (por exemplo, depois de visitantes excluírem boa parte do catálogo), rode o refresh da sua máquina apontando para o Supabase (variáveis só no terminal, nunca num arquivo versionado):

```bash
cd apps/api
DB_URL='<SUPABASE_SESSION_POOLER_URL>' DB_SSLMODE=require DEMO_PASSWORD='<senha da demo>' \
  php artisan pulseboard:demo --refresh
```

O `DatabaseSeeder` (`migrate:fresh --seed`) é só para desenvolvimento: usa factories e cria `test@example.com` / `password`.

**API Keys na demo:** nenhuma chave é criada pelo `pulseboard:demo`, pelo seed ou pelo entrypoint. Visitantes criam as próprias na conta owner da demo; na organização `pulseboard-demo` toda chave expira em 24 horas, e o limite de 10 ativas, o rate limit de 120 req/min por chave e o `--refresh` contêm abusos.

## Insights: custo, cotas e retenção

Os Insights ficam desligados até `AI_ENABLED=true`, e cada organização ainda precisa ativá-los. Antes de cada geração que pode chegar ao provedor, o `AiUsageGuard` aplica, nesta ordem:

| Proteção | Configuração | Resposta |
|----------|--------------|----------|
| Kill switch | `AI_ENABLED` | `503 ai_disabled` (leitura e geração) |
| Opt-in da organização | Configurações › Insights (owner) | `403 ai_not_enabled` |
| Orçamento mensal global | `AI_MONTHLY_BUDGET_USD` (soma de `ai_runs.cost_micros` de todas as organizações no mês UTC; `0` bloqueia tudo) | `503 ai_disabled` até o dia 1º; resumos em cache continuam sendo servidos |
| Cota diária por organização e por usuário | `AI_DAILY_ORG_LIMIT`, `AI_DAILY_USER_LIMIT` (dia no fuso da organização) | `429 ai_quota_exceeded` com `Retry-After` |
| Cota diária por IP (só na demo) | `AI_DEMO_DAILY_IP_LIMIT` | `429 ai_quota_exceeded` com `Retry-After` até a meia-noite da demo |
| Rate limit por minuto | `AI_REQUESTS_PER_MINUTE`, `AI_DEMO_REQUESTS_PER_MINUTE_PER_IP` | `429 rate_limited` |

Cache hits não consomem cota nem orçamento. O login da demo é compartilhado, então a cota por usuário não segura um visitante sozinho; a cota por IP sim. O contador fica no cache (store `database`) com o hash SHA-256 do IP, nunca o IP em claro, e não vai para `ai_runs` nem para o provedor. Gerações recusadas por orçamento ou cota viram uma linha em `ai_runs` com `status=quota_exceeded` (`error_code` `ai_disabled` ou `ai_quota_exceeded`) e custo zero.

O orçamento usa o custo **estimado** a partir dos preços de `config/ai.php`. Configure também o limite de gasto da conta no painel da OpenAI, que é a fonte de verdade da cobrança.

**Retenção.** `pulseboard:ai-prune` apaga resumos em cache (`ai_insights`) com mais de `AI_INSIGHTS_RETENTION_DAYS` (30) e telemetria (`ai_runs`) com mais de `AI_RUNS_RETENTION_DAYS` (90), de todas as organizações, e não mexe em nenhuma outra tabela. Roda a cada start do container pelo `pulseboard:release`, já que o Render Free não tem cron. A retenção de `ai_runs` precisa ser de pelo menos 32 dias, porque as cotas e o orçamento leem essa tabela; um valor menor faz o comando (e o start) falhar. `--dry-run` só conta o que seria apagado.

**Uso e custo.** `pulseboard:ai-usage` mostra, por dia (UTC), organização e modelo: runs, chamadas ao provedor, cache hits, falhas, recusas, tokens, custo estimado e latência p50/p95 das chamadas ao provedor, e termina com o gasto do mês contra o orçamento. Como o Render Free não tem Shell, rode da sua máquina apontando para o Supabase:

```bash
cd apps/api
DB_URL='<SUPABASE_SESSION_POOLER_URL>' DB_SSLMODE=require AI_MONTHLY_BUDGET_USD=2 \
  php artisan pulseboard:ai-usage --days=7 --organization=pulseboard-demo
```

Sem `--organization`, a tabela inclui todas as organizações; a linha do orçamento é sempre global. Repita no terminal o `AI_MONTHLY_BUDGET_USD` do Render para a porcentagem bater.

## Checklist de um novo ambiente

1. Domínio com os subdomínios `app` (front) e `api` (API).
2. Supabase: copiar a connection string do Session pooler (porta 5432).
3. Render: *New → Blueprint* apontando para este repositório (lê o `render.yaml`) e preencher `APP_KEY`, `DB_URL` e `DEMO_PASSWORD`.
4. Nos logs do primeiro deploy, conferir as migrations e a linha "Demo organization seeded"; depois `https://<serviço>.onrender.com/api/v1/health`.
5. Custom domain `api.` no Render (CNAME no DNS) e conferir `https://api.<domínio>/api/v1/health`.
6. Cloudflare Pages com as variáveis de build e o custom domain `app.`.
7. Login no front com `DEMO_OWNER_EMAIL` / `DEMO_PASSWORD`, criar/editar/remover um produto e navegar pelo analytics.
8. Em **API Keys**, criar uma chave, enviar uma transação com ela ([`integration.md`](integration.md#3-envie-uma-transação)), conferir a venda em Transações e a linha JSON nos logs, e revogar a chave.

## Limitações operacionais

- Um único ambiente de produção; sem staging.
- Render Free: o serviço hiberna sem tráfego (primeiro acesso lento) e cada start roda `optimize` + migrations + demo + retenção de IA antes de aceitar requisições. A retenção só é aplicada quando o container sobe.
- Supabase Free: o projeto pausa após dias sem uso.
- Sem monitoramento de erros externo: os logs são o `stderr` do container (JSON), no painel do Render.
- O rate limit da ingestão usa o cache em banco, com escritas extras por requisição; aceitável no volume da demo.
- A conta demo owner pode alterar os dados da demo (é o objetivo); `--refresh` restaura o estado original.
- O cadastro é aberto: visitantes podem criar organizações próprias no banco de produção (com rate limit).
