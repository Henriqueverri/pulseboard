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
2. `php artisan pulseboard:release` — `migrate --force` e depois `pulseboard:demo`, segurando um advisory lock do PostgreSQL: se dois containers sobem ao mesmo tempo (restart durante um deploy), o segundo espera e encontra tudo pronto;
3. PHP-FPM + Nginx na porta `$PORT` (o Render define; padrão 10000).

Qualquer falha nesses passos (banco inacessível, migration quebrada, `DEMO_PASSWORD` ausente ou curta) derruba o container antes de ele aceitar tráfego; num deploy, o Render mantém a versão anterior no ar. O Free hiberna o serviço após ~15 min sem requisições: o primeiro acesso depois disso espera o container subir de novo (e repetir os passos acima, que são idempotentes).

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

Nenhum valor real vai para o Git. No `render.yaml`, os secrets (`APP_KEY`, `DB_URL`, `DEMO_PASSWORD`) usam `sync: false`: o Render pede os valores ao criar o Blueprint.

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
| `DEMO_PASSWORD` | secret — senha das contas de demonstração (mínimo 12 caracteres) |
| `DEMO_OWNER_EMAIL` / `DEMO_MEMBER_EMAIL` | `demo@example.com` / `demo-member@example.com` (padrão) |

`PORT` é definida pelo próprio Render. Localmente, os modelos são [`apps/api/.env.example`](../apps/api/.env.example) e [`apps/web/.env.example`](../apps/web/.env.example).

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
- `--refresh` apaga produtos, clientes e transações **somente da organização de demo** (inclusive o que visitantes criaram) e gera o histórico de novo, terminando no dia atual;
- recusa rodar se um dos e-mails de demo pertence a uma conta de outra organização, ou se o slug pertence a uma organização que não é da conta demo owner.

Em produção ele roda a cada start do container (via `pulseboard:release`), sem `--refresh`. Como o Render Free hiberna após ~15 min sem tráfego, praticamente toda primeira visita sobe o container e completa os dias que faltam — o período padrão do dashboard continua com dados e comparações sem intervenção manual.

Lacunas maiores que 180 dias são preenchidas só nos últimos 180 dias. Para voltar ao estado original (por exemplo, depois de visitantes excluírem boa parte do catálogo), rode o refresh da sua máquina apontando para o Supabase (variáveis só no terminal, nunca num arquivo versionado):

```bash
cd apps/api
DB_URL='<SUPABASE_SESSION_POOLER_URL>' DB_SSLMODE=require DEMO_PASSWORD='<senha da demo>' \
  php artisan pulseboard:demo --refresh
```

O `DatabaseSeeder` (`migrate:fresh --seed`) é só para desenvolvimento: usa factories e cria `test@example.com` / `password`.

## Checklist de um novo ambiente

1. Domínio com os subdomínios `app` (front) e `api` (API).
2. Supabase: copiar a connection string do Session pooler (porta 5432).
3. Render: *New → Blueprint* apontando para este repositório (lê o `render.yaml`) e preencher `APP_KEY`, `DB_URL` e `DEMO_PASSWORD`.
4. Nos logs do primeiro deploy, conferir as migrations e a linha "Demo organization seeded"; depois `https://<serviço>.onrender.com/api/v1/health`.
5. Custom domain `api.` no Render (CNAME no DNS) e conferir `https://api.<domínio>/api/v1/health`.
6. Cloudflare Pages com as variáveis de build e o custom domain `app.`.
7. Login no front com `DEMO_OWNER_EMAIL` / `DEMO_PASSWORD`, criar/editar/remover um produto e navegar pelo analytics.

## Limitações operacionais

- Um único ambiente de produção; sem staging.
- Render Free: o serviço hiberna sem tráfego (primeiro acesso lento) e cada start roda `optimize` + migrations + demo antes de aceitar requisições.
- Supabase Free: o projeto pausa após dias sem uso.
- Sem monitoramento de erros externo: os logs são o `stderr` do container, no painel do Render.
- A conta demo owner pode alterar os dados da demo (é o objetivo); `--refresh` restaura o estado original.
- O cadastro é aberto: visitantes podem criar organizações próprias no banco de produção (com rate limit).
