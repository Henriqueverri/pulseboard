# API — referência

Contratos da API REST do PulseBoard (`/api/v1`). Visão geral do projeto no [README](../README.md); decisões por trás destas regras em [`architecture.md`](architecture.md).

A API tem duas superfícies com autenticações separadas:

- **API interna**, usada pelo front web (sessão Sanctum: cookie + CSRF) e por clientes nativos (Personal Access Token do Sanctum via `Authorization: Bearer`), sempre com `X-Organization-Id`. É o que este documento descreve.
- **API de ingestão** (`/api/v1/ingest/*`), usada por sistemas externos: autenticada só por API Key. Guia completo em [`integration.md`](integration.md); resumo em [Ingestão](#ingestão).

Seções:

- [Autenticação](#autenticação) · [Web (sessão)](#web-sanctum-spa) · [Clientes nativos (token)](#clientes-nativos-personal-access-token)
- [Products e Customers](#products-e-customers)
- [Transactions](#transactions)
- [API Keys](#api-keys)
- [Ingestão](#ingestão)
- [Analytics — regras comuns](#analytics--regras-comuns)
- [Dashboard](#dashboard) · [Revenue](#revenue-analytics) · [Products](#product-analytics) · [Customers](#customer-analytics) · [Transaction status](#transaction-status-analytics)

**Em todas as respostas de `api/*`:** header `X-Request-Id` (o valor recebido, se tiver de 8 a 64 caracteres em `A-Z a-z 0-9 . _ -`, ou um UUID gerado), que também vai em todas as linhas de log da requisição. O CORS expõe `X-Request-Id` e `Retry-After` ao front, que mostra o ID nos estados de erro.

## Autenticação

A API interna aceita duas credenciais, com as mesmas regras de tenant e autorização depois de autenticar:

| Cliente | Credencial | Organização |
|---------|-----------|-------------|
| Front web (navegador) | Sessão Sanctum em cookie httpOnly + CSRF | `X-Organization-Id` + membership |
| Cliente nativo (mobile) | Personal Access Token (PAT) em `Authorization: Bearer`, 30 dias, um por dispositivo | `X-Organization-Id` + membership |

A sessão é verificada primeiro; sem sessão, vale o Bearer. Qualquer Bearer que não seja um PAT válido (inclusive a API Key `pb_…` da ingestão, um token revogado ou expirado) recebe 401 `{"message":"Unauthenticated."}`. O PAT não autentica a ingestão (401 `invalid_api_key`).

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/api/v1/health` | 200 com `{"status":"ok","database":"ok"}`; 503 se o banco não responde |
| `POST` | `/api/v1/auth/register` | Cria usuário + organização + membership `owner` e inicia a sessão |
| `POST` | `/api/v1/auth/login` | Inicia a sessão |
| `POST` | `/api/v1/auth/tokens` | Emite um PAT para um dispositivo, sem sessão |
| `DELETE` | `/api/v1/auth/tokens/current` | Revoga o PAT da requisição |
| `POST` | `/api/v1/auth/logout` | Com sessão, invalida a sessão; com PAT, revoga o token |
| `GET` | `/api/v1/auth/me` | Usuário, organizações e organização atual |
| `GET` | `/api/v1/organization` | Organização do contexto (nome, moeda, timezone) |

Requisições em `api/*` sempre recebem JSON: 401 sem credencial válida, 404 `{"message":"Resource not found."}` para rota ou recurso inexistente.

### Web (Sanctum SPA)

Fluxo cookie httpOnly + CSRF (sem token no `localStorage`):

1. `GET /sanctum/csrf-cookie` (`credentials: include`)
2. `POST /api/v1/auth/register` ou `/login` (throttle de 6 requisições por minuto)
3. Requests seguintes com cookie de sessão
4. Contexto de tenant via header `X-Organization-Id` (contexto apenas; autorização = membership)

Nenhuma resposta do fluxo web expõe token.

### Clientes nativos (Personal Access Token)

Sem cookie, sem CSRF: o app não envia `Origin`/`Referer` de navegador, então a requisição não é tratada como SPA.

**`POST /api/v1/auth/tokens`** — pública, throttle de 6 requisições por minuto (como o login).

| Campo | Regra |
|-------|-------|
| `email` | obrigatório, e-mail |
| `password` | obrigatório |
| `device_name` | obrigatório, até 100 caracteres. Identifica o dispositivo: um novo token com o mesmo `device_name` substitui o anterior desse usuário (o antigo passa a 401); nomes diferentes coexistem |

- Valida as credenciais sem iniciar sessão. Credenciais inválidas → 422 com a mesma mensagem genérica do login (`errors.email`: `The provided credentials are incorrect.`), sem dizer se o e-mail existe.
- Ao emitir, os tokens já expirados do usuário são apagados.
- 201 com `Cache-Control: no-store`. O `token` aparece só nesta resposta (o banco guarda apenas o SHA-256); `user`, `organizations` e `current_organization` têm o mesmo formato de `/auth/me`:

```json
{
  "token": "12|pbm_0b6Yk…",
  "token_type": "Bearer",
  "expires_at": "2026-11-05T23:43:00.000000Z",
  "user": { "id": "…", "name": "Demo Owner", "email": "demo@example.com" },
  "organizations": [{ "id": "…", "name": "PulseBoard Demo Store", "role": "owner", "…": "…" }],
  "current_organization": { "id": "…", "name": "PulseBoard Demo Store", "role": "owner", "…": "…" }
}
```

- Formato `<id>|pbm_<segredo>`: o prefixo `pbm_` permite secret scanning e não se confunde com a API Key `pb_<prefix>_<secret>`.
- Validade de **30 dias**, sem refresh token: expirado → 401 e um novo `POST /auth/tokens`.

**`DELETE /api/v1/auth/tokens/current`** — revoga o token usado na requisição (204); os outros dispositivos continuam válidos. Numa sessão web não há token a revogar: 400 `{"message":"This request is not authenticated with an access token."}`.

**`POST /api/v1/auth/logout`** com PAT revoga o token atual (200 `{"message":"Logged out."}`), como o `DELETE` acima; com sessão, o comportamento é o do web.

Fluxo completo:

```bash
API=http://localhost:8000/api/v1

TOKEN=$(curl -s -X POST "$API/auth/tokens" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"demo@example.com","password":"<senha>","device_name":"Pixel 8 · a1b2"}' | jq -r .token)

ORG=$(curl -s "$API/auth/me" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" \
  | jq -r .current_organization.id)

curl -s "$API/dashboard" -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG"        # 200

curl -s -o /dev/null -w '%{http_code}\n' -X DELETE "$API/auth/tokens/current" \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"       # 204

curl -s -o /dev/null -w '%{http_code}\n' "$API/dashboard" -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG"        # 401
```

O `pulseboard:demo --refresh` apaga os tokens das contas da demo (compartilhadas): os dispositivos fazem login de novo.

## Products e Customers

Rotas de domínio exigem autenticação (sessão ou PAT) + `X-Organization-Id` de uma organização da qual o usuário é membro.

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
- **`external_id`** (opcional, `null` por padrão): o ID do registro no sistema integrado, com até 128 caracteres em `A-Z a-z 0-9 . _ : -`, único por organização incluindo soft-deleted. Aceito no `POST`/`PATCH` e devolvido nas respostas. Na ingestão, o cliente é reconhecido por ele; o produto é reconhecido pelo SKU.

## Transactions

A API interna é **somente leitura** para transações: só existem `GET /transactions` e `GET /transactions/{id}`; outros métodos retornam 405. As transações vêm de duas origens, indicadas em `source`: `seed` (geradas pelo seed ou pelo `pulseboard:demo`) e `ingest` (recebidas pela [API de ingestão](integration.md), que é o único caminho de escrita).

| Filtro | Formato | Regra |
|--------|---------|-------|
| `status` | `paid`, `refunded`, `pending`, `canceled` | Sem filtro, todos os status são retornados |
| `q` | texto | Sempre `external_id` exato; além disso, UUID → id da transaction, outro texto → nome ou e-mail do customer (case-insensitive) |
| `customer_id` | UUID | Customer de outra organização resulta em lista vazia |
| `from` / `to` | `YYYY-MM-DD` | Dias do calendário na timezone da Organization (`organizations.timezone`, padrão `America/Sao_Paulo`): `from` = início do dia, `to` = fim do dia; `from` > `to` → 422 |
| `page` / `per_page` | inteiro | `per_page` 1–100, padrão 15 |

Ordenação: `occurred_at` desc, depois `id` desc. A listagem traz `external_id` (`null` nas transações da demo), `source`, `customer` e `items_count`; o detalhe traz também `items` com `product` e `status_history`. Customers/products soft-deleted continuam aparecendo no histórico com `is_deleted: true`. `unit_price` é o preço no momento da venda. `total_amount`, `unit_price` e `line_total` são strings com 2 casas.

- **`status_history`:** as mudanças de status na ordem do ciclo de vida (a cadeia que começa em `from_status: null`, não a ordem de timestamps). Cada item tem `from_status`, `to_status`, `occurred_at` (horário de negócio informado pela origem), `recorded_at` (quando o PulseBoard gravou) e `source`. A cadeia sempre termina no `status` atual. As transações anteriores ao histórico receberam um backfill: `null → status`, e as `refunded`/`canceled` ganharam o passo intermediário (`paid`/`pending`) no mesmo `occurred_at`, porque a data real é desconhecida.

- **Compatível com analytics:** `from` / `to` usam o mesmo calendário local dos endpoints de analytics. Com os mesmos `from` / `to`, `meta.total` com `status=paid` é igual a `orders` do `/dashboard`, e sem filtro de status é igual à soma de `orders` de `/analytics/transactions`.
- **Tenant e permissões:** transação de outra organização → 404; header sem membership → 403; owner e member podem ler.
- **Consultas:** número fixo por request (listagem: contagem, página com `items_count` e customers; detalhe: transação, customer, itens, produtos e histórico de status), independente do número de resultados ou de itens.

```http
GET /api/v1/transactions?q=order_1002
X-Organization-Id: {organization-uuid}
Accept: application/json
```

```json
{
  "data": [
    {
      "id": "2b9faad1…",
      "external_id": "order_1002",
      "source": "ingest",
      "status": "refunded",
      "total_amount": "114.90",
      "occurred_at": "2026-10-04T18:00:00.000000Z",
      "items_count": 1,
      "customer": { "id": "01a1099b…", "name": "Ana Souza", "email": "ana.souza.17@example.com", "is_deleted": false }
    }
  ],
  "links": { "first": "…?q=order_1002&page=1", "last": "…?q=order_1002&page=1", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 15, "total": 1, "last_page": 1 }
}
```

`GET /api/v1/transactions/{id}`:

```json
{
  "data": {
    "id": "2b9faad1…",
    "external_id": "order_1002",
    "source": "ingest",
    "status": "refunded",
    "total_amount": "114.90",
    "occurred_at": "2026-10-04T18:00:00.000000Z",
    "customer": { "id": "01a1099b…", "name": "Ana Souza", "email": "ana.souza.17@example.com", "is_deleted": false },
    "items": [
      {
        "id": "81198409…",
        "quantity": 1,
        "unit_price": "114.90",
        "line_total": "114.90",
        "product": { "id": "01a1099b…", "name": "Mouse sem fio Pro", "sku": "PB-001-PRO", "is_deleted": false }
      }
    ],
    "status_history": [
      { "from_status": null, "to_status": "pending", "occurred_at": "2026-10-04T18:00:00.000000Z", "recorded_at": "2026-10-05T01:12:23.000000Z", "source": "ingest" },
      { "from_status": "pending", "to_status": "paid", "occurred_at": "2026-10-04T18:05:00.000000Z", "recorded_at": "2026-10-05T01:12:23.000000Z", "source": "ingest" },
      { "from_status": "paid", "to_status": "refunded", "occurred_at": "2026-10-04T21:00:00.000000Z", "recorded_at": "2026-10-05T01:12:24.000000Z", "source": "ingest" }
    ]
  }
}
```

## API Keys

Chaves que autenticam sistemas externos na API de ingestão. Rotas da API interna (sessão ou PAT + `X-Organization-Id`):

| Método | Rota | Quem | Resposta |
|--------|------|------|----------|
| `GET` | `/api/v1/api-keys` | owner e member | 200, todas as chaves da organização (inclusive revogadas e expiradas), mais recentes primeiro, sem paginação |
| `POST` | `/api/v1/api-keys` | owner | 201 com a chave em texto puro, uma única vez |
| `DELETE` | `/api/v1/api-keys/{id}` | owner | 204; revoga (idempotente) e mantém o registro para auditoria |

**Criação:** `{"name": "Loja virtual", "expires_in_days": 90}`.

- `name`: obrigatório, até 100 caracteres.
- `expires_in_days`: `30`, `90`, `365` ou `null`/ausente (sem expiração); outro valor → 422.
- `organization_id`, `prefix` e `secret_hash` no payload → 422: a organização vem do contexto e a chave é gerada no servidor.
- **Organização de demo** (slug `pulseboard-demo`): toda chave expira em 24 horas, qualquer que seja o `expires_in_days`.
- **Limite:** 10 chaves ativas por organização; a 11ª → 422 com `errors.api_keys`. A contagem é serializada por lock na organização, então criações simultâneas não passam do limite.

```json
{
  "data": {
    "id": "01a1099d…",
    "name": "Loja virtual",
    "prefix": "Ab12Cd34Ef56",
    "status": "active",
    "created_by": { "id": "01a1099b…", "name": "Demo Owner" },
    "last_used_at": null,
    "expires_at": "2026-10-06T01:10:46.000000Z",
    "revoked_at": null,
    "created_at": "2026-10-05T01:10:46.000000Z",
    "plain_text_key": "pb_<prefix>_<secret>"
  }
}
```

- `plain_text_key` só existe na resposta do `POST`, que é enviada com `Cache-Control: no-store`. Nenhum endpoint devolve o segredo ou o hash depois disso.
- `status` é derivado: `active`, `revoked` (tem `revoked_at`) ou `expired` (`expires_at` no passado). Chave revogada ou expirada recebe 401 na ingestão.
- `created_by` é `null` se o usuário criador foi removido.
- `last_used_at` é atualizado na autenticação, no máximo uma vez por minuto por chave.
- Chave de outra organização → 404; member em `POST`/`DELETE` → 403 (`"This action requires the owner role."`).

## Ingestão

Rotas autenticadas **só** por `Authorization: Bearer pb_<prefix>_<secret>`: sem sessão, cookie, CSRF ou `X-Organization-Id` (a organização vem da chave; o header é ignorado). Limite de 120 requisições por minuto por chave.

| Método | Rota | Respostas |
|--------|------|-----------|
| `POST` | `/api/v1/ingest/transactions` | 201 criada (`Location`), 200 reenvio idêntico (`Idempotent-Replayed: true`), 409, 422 |
| `GET` | `/api/v1/ingest/transactions/{external_id}` | 200 ou 404 `not_found` |
| `POST` | `/api/v1/ingest/transactions/{external_id}/status-changes` | 201 aplicada, 200 já no status, 404, 409 `invalid_transition`, 422 |

Erros sempre com `message` e `code` estável (`invalid_api_key`, `validation_failed`, `currency_mismatch`, `total_mismatch`, `transaction_conflict`, `customer_email_conflict`, `invalid_transition`, `not_found`, `rate_limited`); 422 com `errors` por campo. As rotas internas recusam o Bearer da integração (401): as duas credenciais não se misturam.

Payload, regras de validação, idempotência, retry, reconciliação, lifecycle e exemplos executados: [`integration.md`](integration.md).

## Analytics — regras comuns

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
- **Tenant isolation:** sessão Sanctum ou PAT obrigatório (401 sem credencial) e header `X-Organization-Id` de uma organização da qual o usuário é membro (403 sem membership). Owner e member podem consultar. `organization_id` na query → 422. Todas as consultas partem da organização do contexto: dados de outra organização nunca aparecem nem alteram os números.

**Consistência entre endpoints** (coberta por testes sobre o seed e com dados controlados):

- `dashboard.revenue` = `revenue.summary.revenue` = `products.summary.revenue` = linha `paid` de `/analytics/transactions` (objeto `Comparison` completo, atual e anterior).
- `dashboard.orders` = `revenue.summary.orders` = `orders` da linha `paid` = `meta.total` de `GET /transactions?status=paid&from&to`.
- `dashboard.customers` = `customers.summary.active_customers`; `new_customers + returning_customers = active_customers`.
- Soma dos buckets (`day`, `week`, `month`) = `summary` do Revenue; soma do ranking completo de produtos = receita e unidades do resumo; soma do ranking completo de clientes = receita e pedidos do Dashboard.
- Soma de `orders` dos 4 status = `meta.total` de `GET /transactions?from&to`; soma do `revenue` dos 4 status = soma de `total_amount` de todas as transações do período.
- O `previous` de cada métrica é igual ao `value` de um request feito para o período anterior.
- Cada bucket diário ou mensal do Revenue tem o mesmo número de pedidos que `GET /transactions?status=paid` com o `from` / `to` do bucket: os dois usam o mesmo calendário local.

**Consultas e performance:** o número de consultas por request é fixo (tabela acima) e travado por teste (`AnalyticsQueryBudgetTest`). Não cresce com o volume de transações, produtos ou clientes, com `limit`, com a granularidade nem com o tamanho do período, e não há N+1. Os períodos atual e anterior são lidos numa única varredura (`CASE` na data de início). Medições em [`architecture.md`](architecture.md#performance).

**Limitações conhecidas:**

- O status usado é o **atual** da transação: um estorno ou cancelamento posterior muda retroativamente os números do período em que a venda ocorreu. O histórico de status já é gravado (`status_history`), mas o analytics ainda não o usa para reconhecer o estorno na data do estorno.
- O período padrão inclui o dia de hoje, ainda incompleto.
- SQLite (suíte rápida de testes) não tem base de timezones: os buckets do Revenue usam o offset fixo do início do período (exato para `America/Sao_Paulo`, sem horário de verão); os testes de DST dos buckets rodam só no PostgreSQL. Os limites de período dos demais endpoints são calculados na aplicação e são exatos nos dois bancos.
- Sem cache: cada request consulta o banco.

## Dashboard

`GET /api/v1/dashboard` devolve os KPIs do período, cada um comparado ao período anterior. Aceita apenas `from` / `to` (regras comuns acima).

**KPIs** — consideram **somente transações `paid`** com `occurred_at` dentro do período:

| KPI | Definição | Tipo |
|-----|-----------|------|
| `revenue` | `SUM(total_amount)` | string decimal (`"0.00"` sem vendas) |
| `orders` | quantidade de transações pagas | inteiro |
| `average_order_value` | `revenue / orders`, arredondado ao centavo (half-up) | string decimal; `null` quando `orders = 0` |
| `customers` | clientes distintos com ao menos uma transação paga no período (inclui clientes soft-deleted que compraram) | inteiro |

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

Sem vendas no período: `revenue` `"0.00"`, `orders` e `customers` `0`, `average_order_value` `null` (e `change` conforme as regras de comparação). Clientes e produtos soft-deleted não alteram o histórico: as vendas deles continuam contando. Os KPIs saem de uma única consulta agregada.

## Revenue analytics

`GET /api/v1/analytics/revenue` devolve a série temporal da receita (somente transações `paid`) no calendário da Organization.

| Parâmetro | Formato | Regra |
|-----------|---------|-------|
| `from` / `to` | `YYYY-MM-DD` | Regras comuns |
| `granularity` | `day`, `week`, `month` | Padrão `day`; outro valor → 422 |

- **Buckets:** `day` = cada dia; `week` = semana ISO (segunda a domingo); `month` = mês do calendário. `bucket` é o primeiro dia local do bucket (numa semana parcial pode ser anterior a `from`); `from` / `to` são os dias efetivamente cobertos, recortados ao período — o primeiro e o último bucket podem ser parciais. Uma granularidade maior que o período gera um único bucket parcial.
- **Todos os buckets do período são retornados**, em ordem cronológica; sem vendas → `"revenue": "0.00"`, `"orders": 0`. A receita é agregada no banco (`GROUP BY` do bucket local) e os buckets vazios são preenchidos na aplicação, sem consultas extras.
- **Timezone:** uma venda em `2026-09-01T02:59:59Z` pertence ao dia `2026-08-31` em `America/Sao_Paulo`. No PostgreSQL a conversão usa a base de timezones (DST exato).
- **`summary`:** `revenue` e `orders` do período comparados ao anterior, com a mesma definição e formato do `/dashboard`.

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

Duas consultas agregadas por request (série + resumo), independentemente do volume ou do número de buckets.

## Product analytics

`GET /api/v1/analytics/products` devolve o ranking dos produtos vendidos no período (somente transações `paid`), cada um comparado ao período anterior.

| Parâmetro | Formato | Regra |
|-----------|---------|-------|
| `from` / `to` | `YYYY-MM-DD` | Regras comuns |
| `sort` | `revenue`, `units_sold` | Padrão `revenue`; outro valor → 422 |
| `limit` | inteiro 1–50 | Padrão 10; fora da faixa → 422. Sem paginação |

- **Receita do produto:** `SUM(line_total)` dos itens pagos, ou seja, o preço no momento da venda (alterar `price` depois não muda o histórico). **Unidades:** `SUM(quantity)`.
- **Entra no ranking** o produto com ao menos uma venda paga no período atual. Produto vendido só no período anterior não aparece em `data`, mas conta em `summary.*.previous`.
- **Ordenação:** `sort=revenue` → receita, unidades, nome, id; `sort=units_sold` → unidades, receita, nome, id. `rank` começa em 1.
- **Produtos soft-deleted e `inactive`** com vendas continuam no ranking (`is_deleted: true` / `status: "inactive"`); compras de clientes soft-deleted também contam.
- **`summary` não depende de `limit`:** considera todos os produtos vendidos. `revenue` é o mesmo objeto do `/dashboard`; `units_sold` e `products_sold` (produtos distintos) no mesmo formato `{ value, previous, change }`.

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

Sem vendas no período: `"data": []` e `summary` zerado. Três consultas agregadas por request (ranking com os dados do produto via join, totais de itens e KPIs do Dashboard), sem N+1.

## Customer analytics

`GET /api/v1/analytics/customers` devolve os indicadores da base de clientes e o ranking dos clientes que mais compraram no período, tudo comparado ao período anterior.

| Parâmetro | Formato | Regra |
|-----------|---------|-------|
| `from` / `to` | `YYYY-MM-DD` | Regras comuns |
| `sort` | `revenue`, `orders` | Padrão `revenue`; outro valor → 422 |
| `limit` | inteiro 1–50 | Padrão 10; fora da faixa → 422. Sem paginação |

**Summary** — cada métrica no formato `{ value, previous, change }`, sempre sobre todos os clientes (independe de `limit`):

| Métrica | Definição |
|---------|-----------|
| `total_customers` | Clientes cadastrados até o fim do período (`created_at` anterior ao fim do dia `to` local) e não excluídos até esse instante. Para um período que termina hoje, igual a `meta.total` de `GET /customers` |
| `active_customers` | Clientes distintos com ao menos uma transação `paid` no período — o mesmo valor de `customers` no `/dashboard` |
| `new_customers` | Clientes ativos cuja **primeira transação `paid` de todo o histórico** ocorreu no período (independe da data de cadastro) |
| `returning_customers` | Clientes ativos que já tinham transação `paid` antes do início do período |

`new_customers + returning_customers = active_customers` (em `value` e em `previous`). Transações `pending`, `refunded` e `canceled` não geram atividade nem contam como primeira compra.

**Ranking** — clientes com ao menos uma transação `paid` no período; `revenue` = `SUM(total_amount)` e `orders` = quantidade de transações pagas (mesmas definições do Dashboard e de `GET /customers/{id}`). Ordenação: `sort=revenue` → receita, pedidos, nome, id; `sort=orders` → pedidos, receita, nome, id.

- **Soft delete:** o histórico não some. Compras de clientes excluídos continuam em `active`, `new`, `returning` e no ranking (`is_deleted: true`); a exclusão só tira o cliente de `total_customers` a partir do instante em que ocorreu.
- **Timezone:** as fronteiras (atividade, primeira compra, cadastro e exclusão) são os limites dos dias no calendário da Organization. Ex.: uma primeira compra em `2026-09-01T02:30:00Z` é de agosto em `America/Sao_Paulo` (cliente recorrente em setembro) e de setembro em `Europe/Lisbon` (cliente novo).

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

Quatro consultas agregadas por request (ranking com os dados do cliente via join, primeira compra/novos/recorrentes, base de clientes e KPIs do Dashboard), sem N+1.

## Transaction status analytics

`GET /api/v1/analytics/transactions` devolve a distribuição das transações do período por status, comparada ao período anterior. Aceita apenas `from` / `to`.

Este endpoint é a **exceção documentada à regra de venda `paid`**: conta transações de todos os status. Cada item de `data` traz `status` e três métricas no formato `{ value, previous, change }`:

| Métrica | Definição |
|---------|-----------|
| `orders` | Quantidade de transações do status no período (transações, não itens) |
| `revenue` | `SUM(total_amount)` das transações do status, em string com 2 casas. Só a linha `paid` é receita no sentido do Dashboard |
| `percentage` | Participação do status no total de transações do período, com 1 casa decimal (float). Sem transações no período → `null` |

- **Ordem fixa e completa:** sempre os 4 status, na ordem `paid`, `refunded`, `pending`, `canceled`; status sem transações aparece zerado.
- **Consistência:** a linha `paid` tem `orders` e `revenue` idênticos ao `/dashboard`; a soma de `orders` dos 4 status é igual a `meta.total` de `GET /transactions?from&to`. Por arredondamento, a soma dos percentuais pode ficar em 100.0 ± 0.2.

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

Uma única consulta agregada por request (agrupada por status, período anterior via `CASE`), sem acessar itens nem clientes.
