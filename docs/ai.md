# PulseBoard Insights (IA)

Uma camada de IA **somente leitura** sobre os services de analytics existentes. Ela não calcula métricas, não acessa o banco e não executa ações: recebe dados agregados montados pela aplicação, devolve texto estruturado que referencia esses dados, e o servidor valida tudo antes de exibir.

> **Estado:** em construção (Fase 9). Este documento começa pelas decisões (ADRs) e é completado ao final da fase com fluxos, configuração, custos, avaliação e limitações.

## Fundação (implementada)

- `LlmClient` (`app/Services/Ai/LlmClient.php`): a fronteira com o provedor, com um único método `respond(LlmRequest): LlmResponse`.
- `OpenAiResponsesClient`: OpenAI Responses API via `Http::`, com `text.format` `json_schema` em modo strict, tools `function` strict, `store: false`, timeout por tentativa, prazo total por run e 1 retry só para falha de conexão, 429 e 5xx.
- `ScriptedLlmClient`: respostas determinísticas para testes, desenvolvimento sem chave e E2E (`AI_PROVIDER=scripted`). Recusa rodar em produção.
- `AiException`: erros com `code` estável (`ai_disabled`, `ai_not_enabled`, `ai_quota_exceeded`, `ai_provider_unavailable`, `ai_timeout`, `ai_invalid_output`).
- `ai_runs` + `AiRunRecorder`: telemetria por run (tokens, custo estimado, latência, status) e uma linha de log `event=ai.run`, sem nenhum conteúdo de prompt ou resposta.
- Opt-in por organização (`PUT /api/v1/organization/insights`, só owner) e `AiUsageGuard` (kill switch, opt-in, cota diária por organização e por usuário).
- `RateLimiter::for('insights')`: por usuário na organização e, na organização de demo (login compartilhado), também por IP.
- `Http::preventStrayRequests()` no `TestCase`: nenhum teste chama a OpenAI.

## Architecture Decision Records

### ADR-1 — Tool calling em vez de Text-to-SQL

- **Contexto:** perguntas em linguagem natural sobre dados multi-tenant, com uma regra de produto de "uma única definição de cada métrica".
- **Opções:** SQL gerado pelo modelo; views semânticas consultadas pelo modelo; tools controladas sobre os services.
- **Decisão:** tools sobre os services de analytics existentes, com allowlist explícita.
- **Consequências:** as perguntas ficam limitadas à cobertura das tools; os números são idênticos aos do produto; o isolamento por organização não depende do prompt. Uma pergunta sem tool que a responda vira `insufficient_data` ou `out_of_scope`.

### ADR-2 — Sem RAG nem embeddings nesta fase

- **Contexto:** o domínio não tem texto não estruturado (produtos, clientes e transações são números e rótulos curtos). As perguntas são sobre agregados com resposta exata.
- **Decisão:** nenhum banco vetorial; um glossário curto de métricas no prompt.
- **Consequências:** zero infraestrutura nova. Reavaliar se surgir um corpus real (descrições livres, tickets, documentação extensa).

### ADR-3 — Structured outputs com referências a métricas

- **Contexto:** modelos erram números com facilidade e com confiança.
- **Opções:** texto livre; JSON com números escritos pelo modelo; JSON com referências (`kpi.revenue`) resolvidas pelo servidor.
- **Decisão:** JSON Schema strict com `evidence` em enum de referências presentes no contexto; texto sem dígitos; o servidor preenche os valores.
- **Consequências:** alucinação numérica deixa de ser representável na saída; a UI renderiza números com os componentes já existentes.

### ADR-4 — A LLM não acessa o banco

- **Decisão:** o modelo só vê o contexto montado pela aplicação (ou o resultado de uma tool executada pelo servidor). Não há tool de SQL, de filtro livre, de escrita ou de chamada HTTP arbitrária.
- **Consequências:** o pior caso de uma injeção de prompt é um texto ruim dentro do próprio tenant, sem efeitos colaterais.

### ADR-5 — `LlmClient` mínimo com um adapter

- **Opções:** SDK do provedor direto nos services; biblioteca multi-provedor; interface própria.
- **Decisão:** uma interface com um método e uma implementação OpenAI sobre `Http::` (nenhuma dependência nova). Reavaliar uma biblioteca só se surgir dor real.
- **Consequências:** services testáveis com um fake; a troca de provedor custa uma classe; não há hierarquia de adapters sem uso.

### ADR-6 — Tenant isolation herdado de `CurrentOrganization`

- **Decisão:** o `AiContext` é construído no controller a partir de `CurrentOrganization`. Context builders e tools nunca leem organização de argumentos do modelo; schemas de tools não têm IDs e usam `additionalProperties: false`.
- **Consequências:** isolamento provado por testes com dados "canário" de outra organização no payload enviado ao provedor, na resposta e no cache.

### ADR-7 — Execução síncrona, sem fila

- **Contexto:** Render Free, sem worker nem Redis; PHP-FPM encerra requests em 60 s.
- **Decisão:** chamada síncrona com timeout de 20 s por tentativa e prazo total de 45 s por run.
- **Consequências:** cache e lock são obrigatórios; fila só se surgir resumo agendado ou em lote.

### ADR-8 — Controle de custos sem infraestrutura nova

- **Decisão:** cache por fingerprint do contexto, cotas diárias por organização e usuário, orçamento mensal global e rate limit, tudo sobre `ai_runs` e o cache store existente.
- **Consequências:** "quanto custou ontem" é uma query; o custo é uma estimativa conferida no painel da OpenAI (onde também há limite de gasto da conta).

### ADR-9 — Validação contra alucinação

- **Decisão:** schema strict no provedor **e** validação local; validação semântica (refs existentes, nenhum dígito, coerência de direção da variação); 1 tentativa de reparo; saída inválida nunca é exibida nem cacheada.

### ADR-10 — Privacidade por minimização e opt-in

- **Decisão:** nada vai ao provedor sem opt-in do owner; só agregados (sem nome ou e-mail de clientes, sem nome da organização, sem UUIDs); `store: false` na API; retenção configurável do cache e da telemetria.

### ADR-11 — Avaliação fora da CI padrão

- **Decisão:** um comando dedicado (`pulseboard:ai-eval`) com casos versionados, rodando com o provedor roteirizado na CI e com o modelo real sob demanda; thresholds para trocar prompt ou modelo.
- **Consequências:** a CI é determinística e sem custo; a qualidade real é medida a cada mudança de prompt ou modelo.

### ADR-12 — Ressalvas calculadas no backend

- **Decisão:** período parcial, ausência de vendas, período anterior sem dados, volume baixo e "status é o atual" são calculados pelo servidor e anexados à resposta, não deixados a cargo do modelo.
