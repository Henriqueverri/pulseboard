import { flushPromises } from '@vue/test-utils'

/** The control associated with a visible `<label>` (dialogs render in a portal on `document.body`). */
export function field(label: string): HTMLInputElement {
  const element = [...document.querySelectorAll('label')].find(item => item.textContent?.trim().startsWith(label))
  if (!element) {
    throw new Error(`No label "${label}"`)
  }

  return document.getElementById(element.htmlFor) as HTMLInputElement
}

export async function type(element: HTMLInputElement, value: string) {
  element.value = value
  element.dispatchEvent(new Event('input'))
  await flushPromises()
}

export async function clickButton(label: string) {
  const button = [...document.querySelectorAll('button')].find(item => item.textContent?.includes(label))
  if (!button) {
    throw new Error(`No button "${label}"`)
  }
  button.click()
  await flushPromises()
}

export function menuItems(): string[] {
  return [...document.querySelectorAll('[role="menuitem"]')].map(item => item.textContent?.trim() ?? '')
}

export function paginated<T>(data: T[]) {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: data.length ? 1 : null, last_page: 1, per_page: 15, to: data.length || null, total: data.length, path: '' },
  }
}
