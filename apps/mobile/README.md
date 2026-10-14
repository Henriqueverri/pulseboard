# PulseBoard Mobile

App Expo (React Native + TypeScript) do PulseBoard: leitura rápida das vendas para o dono do negócio. Consome a mesma API do web (`apps/api`), autenticado por Personal Access Token.

## Setup

Usa **npm** (não Bun):

```bash
cd apps/mobile
npm install
cp .env.example .env   # ajuste EXPO_PUBLIC_API_URL
npm start              # Expo Go ou emulador
```

| Variável | Uso |
|----------|-----|
| `EXPO_PUBLIC_API_URL` | URL base da API. Emulador Android: `http://10.0.2.2:8000/api/v1`; aparelho físico: `http://<IP-da-LAN>:8000/api/v1` (API com `php artisan serve --host=0.0.0.0 --port=8000`) |

`EXPO_PUBLIC_*` vai embutida no bundle: nunca coloque segredos ali.

## Scripts

| Script | O que faz |
|--------|-----------|
| `npm start` | Dev server do Expo |
| `npm run lint` | ESLint (`eslint-config-expo`) |
| `npm run typecheck` | Gera os tipos das rotas (`expo customize tsconfig.json`, typed routes) e roda `tsc --noEmit` |
| `npm test` | Jest (`jest-expo`) + Testing Library + MSW |

## Autenticação

Personal Access Token do Sanctum (`POST /api/v1/auth/tokens`), sem cookie, sem refresh token:

- O token e a organização ativa ficam no **SecureStore** (Keychain/Keystore), nunca em AsyncStorage.
- `device_name` = modelo do aparelho + sufixo aleatório persistido na instalação (`Pixel 8 · a1b2`): um novo login no mesmo aparelho substitui o token anterior sem derrubar outros aparelhos com o mesmo modelo.
- Toda requisição autenticada envia `Authorization: Bearer <token>` e `X-Organization-Id`.
- Ao abrir o app, o token salvo é validado com `GET /auth/me`. 401 em qualquer requisição (token expirado em 30 dias ou revogado) limpa o SecureStore e o cache e volta ao login com aviso. API inacessível mantém o token e oferece nova tentativa.
- Mais de uma organização: o usuário escolhe; a escolha fica salva. Trocar de organização limpa o cache do TanStack Query. 400/403 de contexto de organização volta para a escolha.
- Logout revoga o token (`DELETE /auth/tokens/current`) e apaga o SecureStore, mesmo se a API não responder.

## Dashboard

`GET /dashboard` e `GET /analytics/revenue`, os mesmos contratos usados pela web:

- Períodos: Hoje, 7 dias, 30 dias (padrão, igual ao default da API) e Este mês. `from`/`to` são datas civis calculadas no **timezone da organização** (`src/lib/dates.ts`, via `Intl`), não do aparelho; a API devolve o período anterior equivalente.
- Granularidade `day` até 31 dias, `week` acima disso. O gráfico fica oculto em "Hoje".
- Valores monetários chegam como string decimal e são formatados com a moeda da organização. `change: null` é exibido como "sem base de comparação"; variação abaixo de 1% como "estável".
- Query keys incluem a organização (`['org', id, ...]`). Só erros de rede e 5xx são repetidos automaticamente.

## Transações

`GET /transactions` e `GET /transactions/{id}`, somente leitura:

- Lista paginada com scroll infinito (`per_page=20`, próxima página enquanto `meta.current_page < meta.last_page`) e pull-to-refresh; falha ao carregar mais mantém as linhas e oferece nova tentativa.
- Filtros enviados à API: status, período (mesmos presets do dashboard, no timezone da organização) e busca `q` (nome/e-mail do cliente, ID externo ou ID), aplicada ao confirmar no teclado.
- Linha: cliente, valor, status, data/hora no timezone da organização e selo "Integração" para transações ingeridas.
- Detalhe: itens, cliente, identificação e linha do tempo de `status_history` na ordem da API, com horário de registro quando difere do horário do evento. ID inexistente ou de outra organização (404) mostra "Transação não encontrada"; 401 volta ao login; 403 de organização volta à escolha de organização.

## Conta e falhas de rede

- A aba Conta mostra o usuário, a organização atual (papel, moeda e timezone), a troca de organização com confirmação e a versão do app com o host da API em uso.
- Login lento (> 5 s) avisa que o servidor pode estar acordando: a API de produção roda no plano gratuito do Render e o primeiro acesso pode levar até um minuto.
- API fora do ar ou sem rede: cada tela mostra o erro com "Tentar novamente" e o `X-Request-Id` quando houver resposta; erros de rede e 5xx são repetidos automaticamente duas vezes. Ao voltar para o app, dados com mais de 30 s são recarregados.
- Fora do escopo: modo offline, refresh token, dark mode.

## Notificações push

Avisos de estoque baixo ou zerado e de meta diária batida (regras em [`docs/api.md`](../../docs/api.md#notificações-push)), via `expo-notifications` e Expo Push Service:

- Depois do login (e a cada abertura do app), o app pede a permissão, obtém o Expo push token e o registra em `POST /devices/push-token`. O dispositivo fica preso ao token de acesso: sair da conta já o remove na API.
- A aba Conta tem o switch "Avisos no celular". Desligar remove o dispositivo (`DELETE /devices/push-token`) e fica salvo no aparelho; a permissão não é pedida de novo. Permissão bloqueada mostra um atalho para as configurações do sistema.
- Tocar no aviso troca para a organização dele (se o usuário for membro) e abre Início (estoque) ou Transações (meta). Só rotas conhecidas são aceitas.
- No Android os avisos usam o canal `alerts` ("Alertas", importância alta), criado antes do pedido de permissão.
- **Expo Go:** no Android não recebe push remoto desde o SDK 53 (a Conta avisa e o switch fica desabilitado); é preciso o APK. No iOS o Expo Go recebe.

### Configuração manual (uma vez)

O push no Android passa pelo Firebase Cloud Messaging; as credenciais não ficam no repositório nem na API:

1. Crie um projeto no [Firebase Console](https://console.firebase.google.com) e adicione um app Android com o pacote `dev.henriqueverri.pulseboard`.
2. Baixe o `google-services.json` e envie ao EAS como variável de arquivo, lida pelo `app.config.ts` (sem ela o APK não obtém push token): `npx eas-cli env:create --name GOOGLE_SERVICES_JSON --type file --value ./google-services.json --environment preview --environment production --visibility sensitive`.
3. Em *Configurações do projeto → Contas de serviço*, gere uma chave privada (JSON). **Não commite este arquivo.**
4. Envie a chave ao EAS: `npx eas-cli credentials` → Android → `preview` (e `production`) → *Google Service Account* → *Manage your Google Service Account Key for Push Notifications (FCM V1)* → *Upload a new service account key*.
5. Gere um novo APK (`npx eas-cli build --platform android --profile preview`): o APK anterior não tem `expo-notifications`.

Na API, `PUSH_DRIVER=expo` é o padrão; `EXPO_ACCESS_TOKEN` só é necessário se o *Enhanced Security for Push Notifications* for ligado no projeto Expo. Para testar o envio sem a API: [Expo push notifications tool](https://expo.dev/notifications) com o token registrado.

## Build de preview (APK)

[`eas.json`](eas.json) tem o perfil `preview`: APK de distribuição interna apontando para a API de produção (`https://api.henriqueverri.dev/api/v1`; builds de release Android bloqueiam HTTP sem TLS).

```bash
npx eas-cli login
npx eas-cli build --platform android --profile preview
```

O build roda na nuvem da Expo e exige uma conta Expo: `npx eas-cli init` cria o projeto e informa o `projectId`, que vai em `extra.eas.projectId` no `app.config.ts` (config dinâmica não é editada pelo CLI). O APK funciona com as contas da demo do README raiz.

Os artefatos do EAS expiram, então o APK publicado fica numa release do GitHub, que é o link do README raiz:

```bash
gh release create mobile-v1.0.0 pulseboard-1.0.0.apk --title "PulseBoard Mobile 1.0.0" \
  --notes "APK Android apontando para a API de produção. Contas da demo no README."
```

## Checklist manual no Android

A CI compila o bundle Hermes, mas não executa o app. Antes de publicar um APK, em emulador ou aparelho:

- [ ] Login com a conta da demo; fechar e reabrir o app mantém a sessão.
- [ ] Dashboard: datas do período e "Hoje" batem com o dia no timezone da organização (`Intl` com `timeZone` no Hermes), inclusive perto da meia-noite.
- [ ] Trocar o período atualiza KPIs e gráfico; pull-to-refresh funciona.
- [ ] Transações: rolar até o fim carrega todas as páginas (total igual ao do web); filtros e busca; detalhe com linha do tempo.
- [ ] Modo avião: erro com "Tentar novamente"; voltar a rede e tentar de novo recupera.
- [ ] Sair da conta volta ao login e o token deixa de funcionar.
- [ ] Push: após o login o Android pede permissão; uma venda que zera um produto (POS da demo) gera "Estoque zerado" com o app fechado; tocar abre o Início na organização certa. Desligar o switch na Conta para de receber; religar volta.
- [ ] Fonte do sistema em tamanho grande: textos não cortados; TalkBack lê KPIs, linhas e filtros.

## Estrutura

```text
app/          rotas (Expo Router)
src/api/      client HTTP (Bearer, X-Organization-Id, ApiError), endpoints tipados, QueryClient
src/features/ componentes e hooks por domínio (dashboard, transactions)
src/lib/      datas por timezone, períodos, dinheiro, comparações
src/push/     registro do push token, preferência do aparelho e navegação ao tocar no aviso
src/session/  sessão e SecureStore
src/ui/       tema e componentes base
test/         setup do Jest, servidor MSW e helpers
```
