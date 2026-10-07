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

## Estrutura

```text
app/          rotas (Expo Router)
src/api/      client HTTP (Bearer, X-Organization-Id, ApiError), endpoints tipados, QueryClient
src/session/  sessão e SecureStore
src/ui/       tema e componentes base
test/         setup do Jest, servidor MSW e helpers
```
