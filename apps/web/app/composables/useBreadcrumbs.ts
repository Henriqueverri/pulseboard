import { buildBreadcrumbs } from '~/utils/navigation'

const DETAIL_STATE = 'pb:breadcrumb-detail'

/** Detail pages call `setDetailLabel(name)`; the label is dropped on every navigation. */
export function useBreadcrumbs() {
  const route = useRoute()
  const detail = useState<string | null>(DETAIL_STATE, () => null)

  const crumbs = computed(() => buildBreadcrumbs(route.path, detail.value))

  function setDetailLabel(label: MaybeRefOrGetter<string | null | undefined>) {
    watchEffect(() => {
      detail.value = toValue(label) ?? null
    })
    onBeforeUnmount(() => {
      detail.value = null
    })
  }

  return {
    crumbs,
    setDetailLabel,
  }
}
