/**
 * UI hints only: the API enforces every rule (DELETE answers 403 for members).
 */
export function usePermissions() {
  const store = useAuthStore()

  const isOwner = computed(() => store.organization?.role === 'owner')

  return {
    role: computed(() => store.organization?.role ?? null),
    isOwner,
    canDelete: isOwner,
  }
}
