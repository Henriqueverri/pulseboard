export default defineNuxtPlugin(async () => {
  if (!import.meta.client) {
    return
  }

  const auth = useAuthStore()

  if (!auth.bootstrapped) {
    await auth.bootstrap()
  }
})
