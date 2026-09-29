import { useLocalStorage } from '@vueuse/core'

export const SIDEBAR_STORAGE_KEY = 'pb:sidebar-collapsed'

/**
 * Desktop (≥1280px) keeps the user's collapse preference; tablets always show the rail and
 * phones hide the sidebar. Below desktop the full navigation opens in a drawer.
 */
export function useSidebar() {
  const collapsed = useLocalStorage(SIDEBAR_STORAGE_KEY, false)
  const drawerOpen = useState('pb:nav-drawer', () => false)

  return {
    collapsed,
    drawerOpen,
    toggleCollapsed: () => {
      collapsed.value = !collapsed.value
    },
    openDrawer: () => {
      drawerOpen.value = true
    },
    closeDrawer: () => {
      drawerOpen.value = false
    },
  }
}
