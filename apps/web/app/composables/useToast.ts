export type ToastTone = 'success' | 'danger' | 'info'

export interface ToastMessage {
  id: number
  title: string
  description?: string
  tone: ToastTone
}

let nextId = 1

/** App-wide toast queue rendered by `<UiToastHost>`. */
export function useToast() {
  const toasts = useState<ToastMessage[]>('toasts', () => [])

  function push(toast: Omit<ToastMessage, 'id' | 'tone'> & { tone?: ToastTone }) {
    toasts.value = [...toasts.value, { id: nextId++, tone: 'info', ...toast }]
  }

  function dismiss(id: number) {
    toasts.value = toasts.value.filter(toast => toast.id !== id)
  }

  return {
    toasts,
    push,
    dismiss,
    success: (title: string, description?: string) => push({ title, description, tone: 'success' }),
    error: (title: string, description?: string) => push({ title, description, tone: 'danger' }),
    info: (title: string, description?: string) => push({ title, description, tone: 'info' }),
  }
}
