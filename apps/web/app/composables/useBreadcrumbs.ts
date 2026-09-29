import { buildBreadcrumbs } from '~/utils/navigation'

/** Detail pages call `setDetailLabel(name)` once their record loads. */
export function useBreadcrumbs() {
  const route = useRoute()
  const detail = useState<{ path: string, label: string } | null>('pb:breadcrumb-detail', () => null)

  const crumbs = computed(() => buildBreadcrumbs(
    route.path,
    detail.value?.path === route.path ? detail.value.label : null,
  ))

  function setDetailLabel(label: MaybeRefOrGetter<string | null | undefined>) {
    const path = route.path

    watchEffect(() => {
      const value = toValue(label)
      detail.value = value ? { path, label: value } : null
    })
  }

  return {
    crumbs,
    setDetailLabel,
  }
}
