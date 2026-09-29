import type { RouteLocationRaw } from 'vue-router'

export const HOME_PATH = '/dashboard'
export const LOGIN_PATH = '/login'

/** `auth: 'guest'` for login/register, `false` for pages open to everyone; authenticated otherwise. */
export type RouteAuth = 'guest' | false | undefined

/** Accepts only same-app paths, so `?redirect=` cannot send the user to another site. */
export function safeRedirect(value: unknown): string | null {
  const candidate = Array.isArray(value) ? value[0] : value

  if (typeof candidate !== 'string' || !candidate.startsWith('/') || candidate.startsWith('//') || candidate.includes('\\')) {
    return null
  }

  return candidate
}

export function resolveAuthRedirect(
  route: { auth: RouteAuth, fullPath: string, redirect: unknown },
  isAuthenticated: boolean,
): RouteLocationRaw | null {
  if (route.auth === false) {
    return null
  }

  if (route.auth === 'guest') {
    return isAuthenticated ? (safeRedirect(route.redirect) ?? HOME_PATH) : null
  }

  if (isAuthenticated) {
    return null
  }

  return route.fullPath === '/' || route.fullPath === HOME_PATH
    ? LOGIN_PATH
    : { path: LOGIN_PATH, query: { redirect: route.fullPath } }
}
