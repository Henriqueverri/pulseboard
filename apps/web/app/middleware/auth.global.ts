import { resolveAuthRedirect } from '~/utils/auth-redirect'
import { errorMessage } from '~/utils/api-error'

export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuthStore()
  const routeAuth = to.meta.auth

  if (!auth.bootstrapped) {
    try {
      await auth.bootstrap()
    }
    catch (error) {
      // Guest/public pages still render; protected pages cannot tell if there is a session.
      if (routeAuth !== 'guest' && routeAuth !== false) {
        return abortNavigation(createError({
          statusCode: 503,
          statusMessage: errorMessage(error, 'Não foi possível carregar sua sessão.'),
          fatal: true,
        }))
      }
    }
  }

  const target = resolveAuthRedirect(
    { auth: routeAuth, fullPath: to.fullPath, redirect: to.query.redirect },
    auth.isAuthenticated,
  )

  if (target) {
    return navigateTo(target)
  }
})
