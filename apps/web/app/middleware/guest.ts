export default defineNuxtRouteMiddleware(async () => {
  const auth = useAuthStore()

  if (!auth.bootstrapped) {
    await auth.bootstrap()
  }

  if (auth.isAuthenticated) {
    return navigateTo('/')
  }
})
