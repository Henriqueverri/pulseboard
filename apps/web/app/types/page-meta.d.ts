import type { RouteAuth } from '~/utils/auth-redirect'

declare module '#app' {
  interface PageMeta {
    /** See `middleware/auth.global.ts`. */
    auth?: RouteAuth
  }
}

export {}
