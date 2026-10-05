import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import type { Product } from '~/types/product'
import { ApiError } from '~/utils/api-error'
import { changedFields } from '~/composables/useProducts'
import ProductFormDialog from '~/components/products/ProductFormDialog.vue'
import ProductsPage from '~/pages/products/index.vue'
import { clickButton, field, menuItems, paginated, type } from '../support/dom'

const repository = vi.hoisted(() => ({
  list: vi.fn(),
  get: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  remove: vi.fn(),
}))

vi.mock('~/repositories/productRepository', () => ({
  useProductRepository: () => repository,
}))

const user = { id: 'u1', name: 'Owner', email: 'owner@example.com' }
const org = (role: 'owner' | 'member'): Organization => ({
  id: '0199a000-0000-7000-8000-00000000000a', name: 'Loja', slug: 'loja', currency: 'BRL', timezone: 'America/Sao_Paulo', role,
})

const product: Product = {
  id: '0199a000-0000-7000-8000-000000000001',
  name: 'Mouse sem fio',
  sku: 'MS-01',
  price: '99.90',
  status: 'active',
  external_id: null,
  created_at: '2026-09-01T12:00:00Z',
  updated_at: '2026-09-01T12:00:00Z',
}

const page = paginated<Product>
const submit = clickButton

describe('changedFields', () => {
  it('returns only what changed, comparing prices as amounts', () => {
    expect(changedFields(product, { name: 'Mouse sem fio', sku: 'MS-01', price: '99.9', status: 'active', external_id: null })).toEqual({})
    expect(changedFields(product, { name: 'Mouse', sku: null, price: '89.90', status: 'inactive', external_id: 'prd_1' }))
      .toEqual({ name: 'Mouse', sku: null, price: '89.90', status: 'inactive', external_id: 'prd_1' })
  })
})

describe('ProductFormDialog', () => {
  beforeEach(() => {
    Object.values(repository).forEach(mock => mock.mockReset())
    useAuthStore().setSession(user, org('owner'))
    document.body.innerHTML = ''
  })

  it('sends the price as the API decimal string and an empty SKU as null', async () => {
    repository.create.mockResolvedValue({ ...product, sku: null })
    const wrapper = await mountSuspended(ProductFormDialog, { props: { open: true }, attachTo: document.body })

    await type(field('Nome'), '  Mouse sem fio ')
    await type(field('Preço'), '1.234,5')
    await submit('Criar produto')

    expect(repository.create).toHaveBeenCalledWith({ name: 'Mouse sem fio', sku: null, price: '1234.50', status: 'active', external_id: null })
    expect(wrapper.emitted('saved')?.[0]).toEqual([{ ...product, sku: null }, 'created'])
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false])
    wrapper.unmount()
  })

  it('shows the duplicated SKU error next to the field', async () => {
    repository.create.mockRejectedValue(new ApiError(422, {
      message: 'This SKU is already used by another product in this organization, including deleted products.',
      errors: { sku: ['This SKU is already used by another product in this organization, including deleted products.'] },
    }))
    const wrapper = await mountSuspended(ProductFormDialog, { props: { open: true }, attachTo: document.body })

    await type(field('Nome'), 'Mouse')
    await type(field('SKU'), 'MS-01')
    await type(field('Preço'), '10')
    await submit('Criar produto')

    expect(document.body.textContent).toContain('Este SKU já está em uso por outro produto desta organização')
    expect(field('SKU').getAttribute('aria-invalid')).toBe('true')
    expect(wrapper.emitted('saved')).toBeUndefined()
    wrapper.unmount()
  })

  it('rejects an amount it cannot parse without calling the API', async () => {
    const wrapper = await mountSuspended(ProductFormDialog, { props: { open: true }, attachTo: document.body })

    await type(field('Nome'), 'Mouse')
    await type(field('Preço'), '1,234')
    await submit('Criar produto')

    expect(repository.create).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('Informe um preço válido')
    wrapper.unmount()
  })

  it('patches only the changed fields and skips the request when nothing changed', async () => {
    repository.update.mockResolvedValue({ ...product, price: '89.90' })
    const wrapper = await mountSuspended(ProductFormDialog, {
      props: { 'open': true, product, 'onUpdate:open': (value: boolean) => wrapper.setProps({ open: value }) },
      attachTo: document.body,
    })

    await submit('Salvar alterações')
    expect(repository.update).not.toHaveBeenCalled()
    expect(wrapper.props('open')).toBe(false)

    await wrapper.setProps({ open: true })
    await flushPromises()
    await type(field('Preço'), '89,90')
    await submit('Salvar alterações')

    expect(repository.update).toHaveBeenCalledWith(product.id, { price: '89.90' })
    expect(wrapper.emitted('saved')?.at(-1)).toEqual([{ ...product, price: '89.90' }, 'updated'])
    wrapper.unmount()
  })
})

describe('products page', () => {
  beforeEach(async () => {
    Object.values(repository).forEach(mock => mock.mockReset())
    document.body.innerHTML = ''
    clearNuxtData()
  })

  async function openRowMenu(role: 'owner' | 'member') {
    useAuthStore().setSession(user, org(role))
    repository.list.mockResolvedValue(page([product]))
    const wrapper = await mountSuspended(ProductsPage, { attachTo: document.body })
    await flushPromises()

    await wrapper.get(`button[aria-label="Ações de ${product.name}"]`).trigger('keydown', { key: 'Enter' })
    await flushPromises()

    const items = menuItems()
    wrapper.unmount()

    return items
  }

  it('offers delete to owners', async () => {
    expect(await openRowMenu('owner')).toEqual(['Editar', 'Excluir'])
  })

  it('hides delete from members', async () => {
    expect(await openRowMenu('member')).toEqual(['Editar'])
  })

  it('distinguishes an empty catalog from an empty search', async () => {
    useAuthStore().setSession(user, org('owner'))
    repository.list.mockResolvedValue(page([]))

    const empty = await mountSuspended(ProductsPage)
    await flushPromises()
    expect(empty.text()).toContain('Criar primeiro produto')
    empty.unmount()

    clearNuxtData()
    const filtered = await mountSuspended(ProductsPage, { route: '/products?q=zzz' })
    await flushPromises()
    expect(filtered.text()).toContain('Nenhum produto encontrado')
    expect(filtered.text()).toContain('Limpar filtros')
    expect(repository.list).toHaveBeenLastCalledWith({ q: 'zzz', page: 1, per_page: 15 })
    filtered.unmount()
  })

  it('shows the API failure with a retry', async () => {
    useAuthStore().setSession(user, org('owner'))
    repository.list.mockRejectedValue(new ApiError(500, { message: 'Server Error' }))

    const wrapper = await mountSuspended(ProductsPage)
    await flushPromises()

    expect(wrapper.text()).toContain('O servidor encontrou um erro')
    expect(wrapper.text()).toContain('Tentar novamente')
    wrapper.unmount()
  })
})
