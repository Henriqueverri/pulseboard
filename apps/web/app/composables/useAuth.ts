export function useAuth() {
  const store = useAuthStore()

  return {
    user: computed(() => store.user),
    organization: computed(() => store.organization),
    organizations: computed(() => store.organizations),
    isAuthenticated: computed(() => store.isAuthenticated),
    bootstrapped: computed(() => store.bootstrapped),
    bootstrap: () => store.bootstrap(),
    login: store.login.bind(store),
    register: store.register.bind(store),
    logout: store.logout.bind(store),
  }
}
