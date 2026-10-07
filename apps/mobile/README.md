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
| `npm run typecheck` | `tsc --noEmit` |
| `npm test` | Jest (`jest-expo`) + Testing Library + MSW |

## Autenticação

Personal Access Token do Sanctum (`POST /api/v1/auth/tokens`), sem cookie, sem refresh token:

- O token e a organização ativa ficam no **SecureStore** (Keychain/Keystore), nunca em AsyncStorage.
- `device_name` = modelo do aparelho + sufixo aleatório persistido na instalação (`Pixel 8 · a1b2`): um novo login no mesmo aparelho substitui o token anterior sem derrubar outros aparelhos com o mesmo modelo.
- Toda requisição autenticada envia `Authorization: Bearer <token>` e `X-Organization-Id`.
- Ao abrir o app, o token salvo é validado com `GET /auth/me`. 401 em qualquer requisição (token expirado em 30 dias ou revogado) limpa o SecureStore e o cache e volta ao login com aviso. API inacessível mantém o token e oferece nova tentativa.
- Mais de uma organização: o usuário escolhe; a escolha fica salva. Trocar de organização limpa o cache do TanStack Query. 400/403 de contexto de organização volta para a escolha.
- Logout revoga o token (`DELETE /auth/tokens/current`) e apaga o SecureStore, mesmo se a API não responder.

## Estrutura

```text
app/          rotas (Expo Router)
src/api/      client HTTP (Bearer, X-Organization-Id, ApiError), endpoints tipados, QueryClient
src/session/  sessão e SecureStore
src/ui/       tema e componentes base
test/         setup do Jest, servidor MSW e helpers
```
