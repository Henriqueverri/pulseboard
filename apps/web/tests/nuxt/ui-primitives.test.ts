import { describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import type { VueWrapper } from '@vue/test-utils'
import UiButton from '~/components/ui/Button.vue'
import UiPagination from '~/components/ui/Pagination.vue'
import UiMoneyInput from '~/components/ui/MoneyInput.vue'
import ChangeIndicator from '~/components/data/ChangeIndicator.vue'

describe('UiButton', () => {
  it('emits clicks when enabled', async () => {
    const onClick = vi.fn()
    const wrapper = await mountSuspended(UiButton, {
      attrs: { onClick },
      slots: { default: () => 'Salvar' },
    })

    await wrapper.trigger('click')

    expect(wrapper.text()).toBe('Salvar')
    expect(wrapper.attributes('type')).toBe('button')
    expect(onClick).toHaveBeenCalledOnce()
  })

  it('is disabled and busy while loading', async () => {
    const wrapper = await mountSuspended(UiButton, {
      props: { loading: true, type: 'submit' },
      slots: { default: () => 'Salvar' },
    })

    expect(wrapper.attributes('disabled')).toBeDefined()
    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.attributes('type')).toBe('submit')
    expect(wrapper.find('[role="status"], svg').exists()).toBe(true)
  })

  it('renders a link when `to` is given', async () => {
    const wrapper = await mountSuspended(UiButton, {
      props: { to: '/products' },
      slots: { default: () => 'Produtos' },
    })

    expect(wrapper.element.tagName).toBe('A')
    expect(wrapper.attributes('href')).toBe('/products')
  })
})

describe('UiPagination', () => {
  const meta = (current: number, last: number, total = last * 15) => ({
    current_page: current,
    last_page: last,
    total,
    from: total === 0 ? null : (current - 1) * 15 + 1,
    to: total === 0 ? null : Math.min(current * 15, total),
  })

  const pageLabels = (wrapper: VueWrapper) =>
    wrapper.findAll<HTMLButtonElement>('button[aria-label^="Página "]')
      .filter(button => /^Página \d+$/.test(button.attributes('aria-label') ?? ''))
      .map(button => button.text())

  it('shows every page when there are few pages', async () => {
    const wrapper = await mountSuspended(UiPagination, { props: { meta: meta(2, 5) } })

    expect(pageLabels(wrapper)).toEqual(['1', '2', '3', '4', '5'])
    expect(wrapper.find('[aria-current="page"]').text()).toBe('2')
    expect(wrapper.text()).toContain('Mostrando 16–30')
    expect(wrapper.text()).toContain('de 75')
  })

  it('compacts long page lists around the current page', async () => {
    const wrapper = await mountSuspended(UiPagination, { props: { meta: meta(10, 20) } })

    expect(pageLabels(wrapper)).toEqual(['1', '9', '10', '11', '20'])
  })

  it('emits the requested page and ignores out-of-range navigation', async () => {
    const first = await mountSuspended(UiPagination, { props: { meta: meta(1, 3) } })

    expect(first.get('[aria-label="Página anterior"]').attributes('disabled')).toBeDefined()
    await first.get('[aria-label="Próxima página"]').trigger('click')
    await first.get('[aria-label="Página 3"]').trigger('click')
    await first.get('[aria-label="Página 1"]').trigger('click')
    expect(first.emitted('update:page')).toEqual([[2], [3]])

    const last = await mountSuspended(UiPagination, { props: { meta: meta(3, 3) } })
    expect(last.get('[aria-label="Próxima página"]').attributes('disabled')).toBeDefined()
  })

  it('hides controls for a single page and handles empty results', async () => {
    const single = await mountSuspended(UiPagination, { props: { meta: meta(1, 1, 4) } })
    expect(single.find('[aria-label="Próxima página"]').exists()).toBe(false)
    expect(single.text()).toContain('Mostrando 1–4')

    const empty = await mountSuspended(UiPagination, { props: { meta: meta(1, 1, 0) } })
    expect(empty.text()).toContain('Nenhum resultado')
  })
})

describe('UiMoneyInput', () => {
  it('shows the API decimal in pt-BR format', async () => {
    const wrapper = await mountSuspended(UiMoneyInput, { props: { modelValue: '1234.50' } })

    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('1234,50')
  })

  it('emits the API decimal string while typing', async () => {
    const wrapper = await mountSuspended(UiMoneyInput, { props: { modelValue: null } })
    const input = wrapper.get('input')

    await input.setValue('R$ 99,9')
    expect((input.element as HTMLInputElement).value).toBe('99,9')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['99.90'])

    await input.setValue('1.234,56')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['1234.56'])
  })

  it('emits null for empty or invalid amounts', async () => {
    const wrapper = await mountSuspended(UiMoneyInput, { props: { modelValue: '10.00' } })
    const input = wrapper.get('input')

    await input.setValue('')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null])

    await input.setValue('1,234')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null])
  })
})

describe('ChangeIndicator', () => {
  it.each([
    [{ value: '1250.00', previous: '1000.00', change: 25 }, 'positive', '+25,0%', 'bg-success-soft'],
    [{ value: 6, previous: 9, change: -33.3 }, 'positive', '−33,3%', 'bg-danger-soft'],
    [{ value: 4, previous: 2, change: 100 }, 'negative', '+100,0%', 'bg-danger-soft'],
    [{ value: 0, previous: 0, change: 0 }, 'positive', '0,0%', 'bg-ink/[0.05]'],
    [{ value: '480.00', previous: '0.00', change: null }, 'positive', 'Novo', 'bg-ink/[0.05]'],
  ] as const)('renders %o with polarity %s', async (metric, polarity, text, toneClass) => {
    const wrapper = await mountSuspended(ChangeIndicator, { props: { metric, polarity } })

    expect(wrapper.get('[aria-hidden="true"]:not(svg)').text()).toBe(text)
    expect(wrapper.classes()).toContain(toneClass)
    expect(wrapper.get('.sr-only').text()).not.toBe('')
  })

  it('renders a muted dash when there is nothing to compare', async () => {
    const wrapper = await mountSuspended(ChangeIndicator, {
      props: { metric: { value: null, previous: '10.00', change: null } },
    })

    expect(wrapper.text()).toContain('—')
    expect(wrapper.classes()).toContain('text-ink/40')
    expect(wrapper.find('svg').exists()).toBe(false)
  })
})
