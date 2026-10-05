# Integração: API de ingestão

Guia para quem vai enviar vendas de um sistema externo (loja, ERP, checkout) para o PulseBoard. Ele basta para integrar: autenticação, payload, respostas, idempotência, retry, reconciliação e mudanças de status. Contratos da API interna em [`api.md`](api.md); decisões por trás destas regras em [`architecture.md`](architecture.md#ingestão-de-transações).

| | |
|---|---|
| **URL base** | `https://api.henriqueverri.dev/api/v1` (produção) · `http://localhost:8000/api/v1` (local) |
| **Autenticação** | `Authorization: Bearer <API Key>` da organização |
| **Formato** | JSON (`Content-Type: application/json`, `Accept: application/json`) |
| **Modelo** | Síncrono, uma transação por requisição; a resposta já diz se a venda foi gravada |
| **Limite** | 120 requisições por minuto por chave |

Todos os exemplos abaixo foram executados contra a API local, com as respostas reais (UUIDs encurtados). Eles usam duas variáveis de ambiente, para que a chave nunca apareça no comando nem no histórico do shell:

```bash
export PULSEBOARD_API_URL="https://api.henriqueverri.dev/api/v1"
read -rs PULSEBOARD_API_KEY && export PULSEBOARD_API_KEY   # cole a chave e tecle Enter
```

## Endpoints

| Método | Rota | Uso |
|--------|------|-----|
| `POST` | `/ingest/transactions` | Registra uma transação (idempotente por `external_id`) |
| `GET` | `/ingest/transactions/{external_id}` | Reconciliação: a transação foi gravada? Em que status está? |
| `POST` | `/ingest/transactions/{external_id}/status-changes` | Muda o status (pagamento, estorno, cancelamento) |

Não há endpoint de lote, de edição nem de exclusão: cada venda é uma requisição, e o histórico não se apaga (cancelamento e estorno cobrem esses casos).

## 1. Crie uma API Key

1. Entre no PulseBoard com uma conta **owner** da organização e abra **Integração → API Keys** (`/settings/api-keys`).
2. Clique em **Nova chave**, dê um nome que identifique o sistema (por exemplo, "Loja virtual") e escolha a validade: 30, 90 ou 365 dias, ou sem expiração.
3. Copie a chave na janela seguinte. **Ela aparece uma única vez:** o PulseBoard guarda só um hash e não consegue mostrá-la de novo. Se perder, revogue e crie outra.

- A chave tem o formato `pb_<prefixo>_<segredo>`: 12 caracteres públicos que identificam a chave na tela e nos logs, mais 40 de segredo. O prefixo `pb_` permite que ferramentas de secret scanning detectem vazamentos.
- Members veem a lista de chaves (nome, prefixo, criador, último uso, validade e status), mas só owners criam e revogam.
- Cada organização tem no máximo 10 chaves ativas.
- A revogação vale na hora: a próxima requisição com a chave recebe 401. A chave continua listada como "Revogada" para auditoria.
- A chave determina a organização. Não existe header de organização na integração; mandar `X-Organization-Id` não muda nada.

**Guarde a chave como uma senha:** num cofre de segredos ou numa variável de ambiente do servidor. Nunca no código-fonte, no front-end, numa URL ou em logs. Para trocar a chave sem parar a integração, crie uma nova, publique-a no seu sistema e só então revogue a antiga.

## 2. Pré-requisitos no cadastro

- **Produtos são identificados pelo SKU.** Todo `items[].sku` precisa existir no catálogo da organização (cadastre em **Produtos**). Produtos `inactive` são aceitos, porque a venda é um fato; produtos excluídos e SKUs desconhecidos recebem 422.
- **Clientes são identificados pelo `customer.external_id`** (o ID do cliente no seu sistema):
  - se ele já existe na organização, é usado como está; nome e e-mail enviados **não** atualizam o cadastro;
  - se não existe, o cliente é criado com o `name` e o `email` do payload;
  - se o e-mail já pertence a outro cliente (por exemplo, um cadastrado à mão na tela), a API responde 409 `customer_email_conflict` e nada é gravado. Para vincular esse cliente, preencha o campo **ID externo** dele na tela de Clientes com o ID do seu sistema e reenvie;
  - cliente excluído recebe 422.
- **Moeda:** `currency` precisa ser a moeda da organização (BRL na demo). Não há conversão.

## 3. Envie uma transação

```bash
curl -sS -i -X POST "$PULSEBOARD_API_URL/ingest/transactions" \
  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Request-Id: checkout-order-1001" \
  --data @- <<'JSON'
{
  "external_id": "order_1001",
  "status": "paid",
  "occurred_at": "2026-10-04T14:30:00-03:00",
  "currency": "BRL",
  "customer": {
    "external_id": "cus_42",
    "name": "Ana Lima",
    "email": "ana@example.com"
  },
  "items": [
    { "sku": "PB-001-ESS", "quantity": 2, "unit_price": "79.90" },
    { "sku": "PB-002-ESS", "quantity": 1, "unit_price": "249.90" }
  ],
  "total_amount": "409.70"
}
JSON
```

```http
HTTP/1.1 201 Created
Location: /api/v1/ingest/transactions/order_1001
X-Request-Id: checkout-order-1001
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 119
```

```json
{
  "data": {
    "id": "31fd9e22…",
    "external_id": "order_1001",
    "status": "paid",
    "currency": "BRL",
    "total_amount": "409.70",
    "occurred_at": "2026-10-04T17:30:00.000000Z",
    "customer": { "id": "01a1099e…", "external_id": "cus_42" },
    "items": [
      { "product_id": "01a1099b…", "sku": "PB-001-ESS", "quantity": 2, "unit_price": "79.90", "line_total": "159.80" },
      { "product_id": "01a1099b…", "sku": "PB-002-ESS", "quantity": 1, "unit_price": "249.90", "line_total": "249.90" }
    ],
    "status_history": [
      { "from_status": null, "to_status": "paid", "occurred_at": "2026-10-04T17:30:00.000000Z", "recorded_at": "2026-10-05T01:12:23.000000Z", "source": "ingest" }
    ]
  }
}
```

A resposta identifica tudo pelos seus IDs (`external_id`, `sku`, `customer.external_id`) e não devolve dados pessoais do cliente. `occurred_at` volta em UTC; `recorded_at` é quando o PulseBoard gravou.

### Campos

| Campo | Regra |
|-------|-------|
| `external_id` | Obrigatório. ID da venda no seu sistema: até 128 caracteres em `A-Z a-z 0-9 . _ : -`. Único por organização |
| `status` | Obrigatório: `pending` ou `paid`. Estorno e cancelamento são mudanças posteriores ([seção 7](#7-mudanças-de-status)) |
| `occurred_at` | Obrigatório. ISO 8601 **com fuso** (`2026-10-04T14:30:00-03:00` ou `…Z`); sem fuso → 422. Aceito até 5 minutos no futuro |
| `currency` | Obrigatório. Código de 3 letras maiúsculas, igual à moeda da organização |
| `customer.external_id` | Obrigatório. Mesmo formato do `external_id` |
| `customer.name`, `customer.email` | Obrigatórios (usados só quando o cliente é novo). E-mail normalizado para minúsculas |
| `items` | De 1 a 100 itens; cada SKU no máximo uma vez por transação |
| `items[].sku` | Obrigatório. SKU de um produto da organização |
| `items[].quantity` | Inteiro de 1 a 10.000 |
| `items[].unit_price` | **String decimal** com até 2 casas (`"79.90"`). Número JSON → 422, para não existir float em nenhum ponto. É o preço real da venda: mudar o preço do produto depois não altera o histórico |
| `total_amount` | Opcional. Se enviado, precisa ser igual à soma dos itens, senão 422 `total_mismatch`. Serve como conferência do seu lado; o total gravado é sempre calculado pelo servidor |

Campos fora dessa lista dentro de `customer` e de `items[]` são rejeitados; `id` e `organization_id` na raiz também.

## 4. Respostas

Toda resposta traz `X-Request-Id`. Erros têm sempre `message` (texto para pessoas) e `code` (estável, para o seu código decidir); erros de validação trazem também `errors`, por campo.

| HTTP | `code` | Significado | O que fazer |
|------|--------|-------------|-------------|
| 201 | — | Transação criada. Header `Location` aponta para ela | Pronto |
| 200 | — | Reenvio idêntico: a transação já existia. Header `Idempotent-Replayed: true`, corpo com o estado atual | Pronto, trate como sucesso |
| 401 | `invalid_api_key` | Chave ausente, malformada, desconhecida, revogada ou expirada (a resposta não diz qual, de propósito). Header `WWW-Authenticate: Bearer` | Confira a chave; não repita |
| 404 | `not_found` | `external_id` inexistente nesta organização (só no `GET` e nas mudanças de status) | Confira o ID |
| 409 | `transaction_conflict` | O `external_id` já existe com outros dados | Não repita: é outra venda com ID repetido, ou o payload mudou |
| 409 | `customer_email_conflict` | O e-mail do cliente novo já pertence a outro cliente | Vincule o cliente pelo ID externo na UI e reenvie |
| 409 | `invalid_transition` | Mudança de status não permitida | Não repita; confira o status atual com `GET` |
| 413 | — | Corpo acima de 2 MB (limite do servidor web em produção) | Reduza o payload |
| 422 | `validation_failed` | Campos inválidos, SKU desconhecido, cliente excluído ou mudança de status mais antiga que a anterior | Corrija o payload conforme `errors` |
| 422 | `currency_mismatch` | Moeda diferente da organização | Corrija `currency` |
| 422 | `total_mismatch` | `total_amount` diferente da soma dos itens | Corrija o total ou os itens |
| 429 | `rate_limited` | Mais de 120 requisições no minuto para esta chave | Espere os segundos de `Retry-After` e repita |
| 5xx | — | Erro do servidor (`{"message": "Server Error"}`) | Repita o mesmo payload; guarde o `X-Request-Id` |

Regra prática: **429, 5xx e falhas de rede (timeout, conexão) são transitórios e podem ser repetidos com o mesmo payload; os demais 4xx são definitivos** e repetir não muda o resultado.

## 5. Idempotência e retry

O `external_id` é a chave de idempotência: o banco tem uma restrição única em (organização, `external_id`), então **a mesma venda nunca é gravada duas vezes**, mesmo com duas requisições simultâneas. Não existe header `Idempotency-Key` separado.

Ao receber um `external_id` que já existe, a API compara o conteúdo com o original:

- **mesmo conteúdo → 200** com `Idempotent-Replayed: true` e o estado atual (que pode já ter outro status, se houve mudanças depois);
- **conteúdo diferente → 409** `transaction_conflict`.

"Mesmo conteúdo" considera `external_id`, `occurred_at` (o mesmo instante, em qualquer fuso), `currency`, `status` inicial, `customer.external_id` e os itens (SKU, quantidade e preço, **em qualquer ordem**). Nome e e-mail do cliente e `total_amount` ficam de fora, porque não alteram a venda gravada. Por isso este reenvio, com os itens em outra ordem e sem `total_amount`, é um replay:

```bash
curl -sS -i -X POST "$PULSEBOARD_API_URL/ingest/transactions" \
  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data @- <<'JSON'
{
  "external_id": "order_1001",
  "status": "paid",
  "occurred_at": "2026-10-04T14:30:00-03:00",
  "currency": "BRL",
  "customer": { "external_id": "cus_42", "name": "Ana Lima", "email": "ana@example.com" },
  "items": [
    { "sku": "PB-002-ESS", "quantity": 1, "unit_price": "249.90" },
    { "sku": "PB-001-ESS", "quantity": 2, "unit_price": "79.90" }
  ]
}
JSON
```

```http
HTTP/1.1 200 OK
Idempotent-Replayed: true
```

Já o mesmo `external_id` com outra quantidade é um conflito:

```bash
curl -sS -i -X POST "$PULSEBOARD_API_URL/ingest/transactions" \
  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data @- <<'JSON'
{
  "external_id": "order_1001",
  "status": "paid",
  "occurred_at": "2026-10-04T14:30:00-03:00",
  "currency": "BRL",
  "customer": { "external_id": "cus_42", "name": "Ana Lima", "email": "ana@example.com" },
  "items": [
    { "sku": "PB-001-ESS", "quantity": 3, "unit_price": "79.90" }
  ]
}
JSON
```

```http
HTTP/1.1 409 Conflict
```

```json
{ "message": "A transaction with this external id already exists with different data.", "code": "transaction_conflict" }
```

**Vários sistemas na mesma organização:** a unicidade é por organização, não por chave (trocar de chave não abre brecha para duplicatas). Se duas origens podem gerar o mesmo número de pedido, use um prefixo por origem: `loja:1001`, `marketplace:1001`.

### Script de retry

O script abaixo repete **o mesmo arquivo** (portanto o mesmo `external_id`) em timeout, falha de rede, 429 e 5xx, respeitando o `Retry-After`, com espera crescente nas demais falhas; qualquer outro 4xx encerra na hora. Como o reenvio idêntico devolve 200, repetir depois de um timeout nunca duplica a venda.

```bash
#!/usr/bin/env bash
# Envia uma transação e repete o MESMO payload (mesmo external_id) enquanto a falha
# for transitória. Uso: ./send-transaction.sh pedido.json
set -euo pipefail

payload_file="$1"
max_attempts=5
delay=1
body=$(mktemp)
headers=$(mktemp)
trap 'rm -f "$body" "$headers"' EXIT

for attempt in $(seq 1 "$max_attempts"); do
  : > "$headers"
  status=$(curl -sS --max-time 15 -o "$body" -D "$headers" -w '%{http_code}' \
    -X POST "$PULSEBOARD_API_URL/ingest/transactions" \
    -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    --data @"$payload_file") || status=000

  case "$status" in
    200|201)
      echo "ok: HTTP $status (tentativa $attempt)"
      cat "$body"; echo
      exit 0 ;;
    000|429|5??)
      ;; # timeout, rede, limite ou erro do servidor: repetir o mesmo payload
    *)
      echo "erro definitivo: HTTP $status; corrija o payload antes de reenviar" >&2
      cat "$body" >&2; echo >&2
      exit 1 ;;
  esac

  if (( attempt == max_attempts )); then
    break
  fi

  retry_after=$(awk 'tolower($1) == "retry-after:" { print $2 + 0 }' "$headers")
  wait_seconds=${retry_after:-$delay}
  echo "HTTP $status na tentativa $attempt; nova tentativa em ${wait_seconds}s" >&2
  sleep "$wait_seconds"
  delay=$(( delay * 2 ))
done

echo "sem resposta definitiva após $max_attempts tentativas; consulte GET /ingest/transactions/{external_id}" >&2
exit 1
```

Saídas reais do script: um envio que encontrou o limite de requisições e concluiu depois do `Retry-After`, e um payload inválido, que não é repetido:

```text
$ ./send-transaction.sh pedido.json
HTTP 429 na tentativa 1; nova tentativa em 7s
ok: HTTP 201 (tentativa 2)
{"data":{"id":"95ff2c4a…","external_id":"order_1006","status":"paid", …}}

$ ./send-transaction.sh pedido-usd.json
erro definitivo: HTTP 422; corrija o payload antes de reenviar
{"message":"The transaction currency does not match the organization currency.","code":"currency_mismatch","errors":{"currency":["The currency must be BRL."]}}
```

Se as tentativas acabarem sem resposta definitiva, não descarte a venda nem gere outro `external_id`: consulte a reconciliação (próxima seção) e reenvie o mesmo payload mais tarde.

## 6. Reconciliação

É a resposta para "a requisição deu timeout; a venda foi gravada?":

```bash
curl -sS -i "$PULSEBOARD_API_URL/ingest/transactions/order_1001" \
  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
  -H "Accept: application/json"
```

Responde 200 com o mesmo corpo da criação (estado e histórico atuais) ou, se a venda não foi gravada:

```bash
curl -sS -i "$PULSEBOARD_API_URL/ingest/transactions/order_9999" \
  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
  -H "Accept: application/json"
```

```http
HTTP/1.1 404 Not Found
```

```json
{ "message": "Transaction not found.", "code": "not_found" }
```

Uma transação de outra organização também responde 404: para a sua chave, ela não existe.

## 7. Mudanças de status

```text
pending ──► paid ──► refunded
   │
   └──────► canceled
```

- Uma transação nasce `pending` ou `paid`. As transições permitidas são `pending → paid`, `pending → canceled` e `paid → refunded`; `refunded` e `canceled` são finais.
- Uma venda que já chegou estornada é importada em dois passos: criada como `paid` e depois mudada para `refunded`.
- `occurred_at` é o horário de negócio da mudança, no mesmo formato da criação, e não pode ser anterior ao da mudança anterior.
- Cada mudança entra no histórico (`status_history`), que aparece nas respostas e na linha do tempo da transação no PulseBoard.

Exemplo com um pedido criado como `pending` para um cliente que já existia (`demo-cus-017`, da demo) e depois pago:

```bash
curl -sS -i -X POST "$PULSEBOARD_API_URL/ingest/transactions/order_1002/status-changes" \
  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"status": "paid", "occurred_at": "2026-10-04T15:05:00-03:00"}'
```

```http
HTTP/1.1 201 Created
```

```json
{
  "data": {
    "id": "2b9faad1…",
    "external_id": "order_1002",
    "status": "paid",
    "currency": "BRL",
    "total_amount": "114.90",
    "occurred_at": "2026-10-04T18:00:00.000000Z",
    "customer": { "id": "01a1099b…", "external_id": "demo-cus-017" },
    "items": [
      { "product_id": "01a1099b…", "sku": "PB-001-PRO", "quantity": 1, "unit_price": "114.90", "line_total": "114.90" }
    ],
    "status_history": [
      { "from_status": null, "to_status": "pending", "occurred_at": "2026-10-04T18:00:00.000000Z", "recorded_at": "2026-10-05T01:12:23.000000Z", "source": "ingest" },
      { "from_status": "pending", "to_status": "paid", "occurred_at": "2026-10-04T18:05:00.000000Z", "recorded_at": "2026-10-05T01:12:23.000000Z", "source": "ingest" }
    ]
  }
}
```

| Situação | Resposta |
|----------|----------|
| Transição aplicada | 201 com o estado e o histórico atualizados |
| A transação já está no status pedido (retry) | 200 com `Idempotent-Replayed: true`, nada muda |
| Transição não permitida, por exemplo `paid → canceled` | 409 `invalid_transition`: `"A transaction cannot go from paid to canceled."` |
| `occurred_at` anterior à mudança anterior | 422 `validation_failed` com `errors.occurred_at` |
| `external_id` inexistente | 404 `not_found` |

```bash
curl -sS -i -X POST "$PULSEBOARD_API_URL/ingest/transactions/order_1002/status-changes" \
  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"status": "canceled", "occurred_at": "2026-10-04T16:00:00-03:00"}'
```

```json
{ "message": "A transaction cannot go from paid to canceled.", "code": "invalid_transition" }
```

```bash
curl -sS -i -X POST "$PULSEBOARD_API_URL/ingest/transactions/order_1002/status-changes" \
  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"status": "refunded", "occurred_at": "2026-10-04T15:00:00-03:00"}'
```

```json
{
  "message": "The status change is older than the previous one.",
  "code": "validation_failed",
  "errors": { "occurred_at": ["The occurred at field must not be before the previous status change."] }
}
```

## 8. Erros de validação

Cada erro vem por campo em `errors`, com o caminho do campo (`items.1.sku` é o SKU do segundo item):

```bash
curl -sS -i -X POST "$PULSEBOARD_API_URL/ingest/transactions" \
  -H "Authorization: Bearer $PULSEBOARD_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data @- <<'JSON'
{
  "external_id": "order_1003",
  "status": "paid",
  "occurred_at": "2026-10-04 16:00:00",
  "currency": "BRL",
  "customer": { "external_id": "cus_42", "name": "Ana Lima", "email": "ana@example.com" },
  "items": [
    { "sku": "PB-001-ESS", "quantity": 1, "unit_price": 79.9 }
  ]
}
JSON
```

```json
{
  "message": "The given data was invalid.",
  "code": "validation_failed",
  "errors": {
    "occurred_at": ["The occurred at field must be an ISO 8601 datetime with a timezone offset."],
    "items.0.unit_price": ["The unit price must be sent as a decimal string."]
  }
}
```

Outras respostas reais, para o mesmo pedido com um problema de cada vez:

| Problema | Resposta |
|----------|----------|
| Segundo item com SKU inexistente | 422 `validation_failed`, `errors: { "items.1.sku": ["The selected product SKU is invalid."] }` |
| `"currency": "USD"` numa organização em BRL | 422 `currency_mismatch`, `errors: { "currency": ["The currency must be BRL."] }` |
| `"total_amount": "80.00"` para um item de `"79.90"` | 422 `total_mismatch`, `errors: { "total_amount": ["The total amount does not match the sum of the items."] }` |
| Cliente novo (`cus_77`) com o e-mail de um cliente existente | 409 `customer_email_conflict` |

Nos erros de validação a transação não é gravada, então o mesmo `external_id` pode ser reenviado depois da correção.

## 9. Limites

- **120 requisições por minuto por chave**, somando os três endpoints. Os headers `X-RateLimit-Limit` e `X-RateLimit-Remaining` mostram o consumo; acima do limite:

  ```http
  HTTP/1.1 429 Too Many Requests
  Retry-After: 7
  X-RateLimit-Limit: 120
  X-RateLimit-Remaining: 0
  ```

  ```json
  { "message": "Too many requests.", "code": "rate_limited" }
  ```

- Até 100 itens por transação e 2 MB de corpo.
- `occurred_at` até 5 minutos no futuro (tolerância para relógios dessincronizados).
- Valores até `9999999999.99`, sempre como string decimal.

## 10. Rastreabilidade

Toda resposta, inclusive de erro, traz `X-Request-Id`. Se você enviar um `X-Request-Id` próprio (de 8 a 64 caracteres em `A-Z a-z 0-9 . _ -`), ele é mantido; senão a API gera um UUID. Usar o ID do seu próprio log (como `checkout-order-1001` no primeiro exemplo) facilita cruzar os dois lados.

Para pedir suporte, informe o `X-Request-Id` ou o `external_id`. Do lado do PulseBoard, cada requisição gera uma linha de log com o resultado (`created`, `replayed`, `conflict`, `rejected`, `transitioned`), o `code`, o prefixo da chave e a duração; a chave, o header `Authorization` e os dados pessoais do cliente nunca são registrados.

## 11. Onde a venda aparece no PulseBoard

- **Transações:** a venda aparece com a origem "Integração" e pode ser buscada pelo `external_id`. O detalhe mostra itens, cliente, identificação e a linha do tempo de status.
- **Dashboard e analytics:** a transação entra na mesma tabela e nas mesmas consultas das demais, então uma venda `paid` muda receita, pedidos e rankings na próxima leitura. Os números consideram o status **atual**: um estorno posterior tira a venda do período em que ela ocorreu (limitação conhecida, ver [`architecture.md`](architecture.md#limitações-conhecidas)).
- **API Keys:** a coluna "Último uso" mostra quando a chave autenticou pela última vez (atualizada no máximo uma vez por minuto).

## Testando na demo

A demo pública não publica nenhuma chave: cada pessoa cria a sua.

1. Entre em [app.henriqueverri.dev](https://app.henriqueverri.dev) com a conta owner da demo ([credenciais no README](../README.md#demo)).
2. Crie uma chave em **Integração → API Keys**. Na organização de demo, **toda chave expira em 24 horas**, qualquer que seja a validade escolhida.
3. Use SKUs do catálogo da demo (por exemplo, `PB-001-ESS`) e clientes novos ou existentes (os da demo têm IDs externos `demo-cus-001` a `demo-cus-070`).
4. Envie as requisições deste guia com `PULSEBOARD_API_URL=https://api.henriqueverri.dev/api/v1` e veja a venda em **Transações**.
5. Revogue a chave ao terminar.

A demo é compartilhada: se a criação falhar porque a organização já tem 10 chaves ativas, revogue uma chave antiga na mesma tela (todas expiram em 24 horas de qualquer forma). Os dados de visitantes podem ser restaurados ao estado original a qualquer momento.
