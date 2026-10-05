import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { useAuthStore } from '~/stores/auth'
import type { Organization } from '~/types/auth'
import type { ApiKey, CreatedApiKey } from '~/types/api-key'
import { ApiError } from '~/utils/api-error'
import { expirationWasLimited } from '~/composables/useApiKeys'
import ApiKeysPage from '~/pages/settings/api-keys.vue'
import { clickButton, field, type } from '../support/dom'

const repository = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn(), revoke: vi.fn() }))

vi.mock('~/repositories/apiKeyRepository', () => ({
  useApiKeyRepository: () => repository,
}))

const user = { id: 'u1', name: 'Owner', email: 'owner@example.com' }
const org = (role: 'owner' | 'member'): Organization => ({
  id: '0199a000-0000-7000-8000-00000000000a', name: 'Loja', slug: 'loja', currency: 'BRL', timezone: 'America/Sao_Paulo', insights: { available: false, enabled: false }, role,
})

const active: ApiKey = {
  id: 'k1',
  name: 'ERP produção',
  prefix: 'AbCdEfGh1234',
  status: 'active',
  created_by: { id: 'u1', name: 'Owner' },
  last_used_at: '2026-10-01T15:00:00Z',
  expires_at: '2026-12-30T15:00:00Z',
  revoked_at: null,
  created_at: '2026-10-01T12:00:00Z',
}

const revoked: ApiKey = {
  ...active, id: 'k2', name: 'Teste antigo', prefix: 'ZyXwVuTs9876', status: 'revoked', last_used_at: null, revoked_at: '2026-10-02T12:00:00Z',
}

const expired: ApiKey = {
  ...active, id: 'k3', name: 'Demo', prefix: 'Qwerty123456', status: 'expired', created_by: null, expires_at: '2026-10-02T12:00:00Z',
}

/** Shape of a real key; built at runtime so no key-like literal lives in the repository. */
const SECRET = ['pb', 'NeWkEy123456', 'S'.repeat(40)].join('_')

function created(overrides: Partial<CreatedApiKey> = {}): CreatedApiKey {
  return {
    ...active,
    id: 'k4',
    name: 'Nova integração',
    prefix: 'NeWkEy123456',
    last_used_at: null,
    created_at: '2026-10-04T12:00:00Z',
    expires_at: '2027-01-02T12:00:00Z',
    plain_text_key: SECRET,
    ...overrides,
  }
}

function dialogText(): string {
  return [...document.querySelectorAll('[role="dialog"], [role="alertdialog"]')].map(dialog => dialog.textContent ?? '').join(' ')
}

beforeEach(() => {
  Object.values(repository).forEach(mock => mock.mockReset())
  document.body.innerHTML = ''
  clearNuxtData()
  localStorage.clear()
  sessionStorage.clear()
})

describe('expirationWasLimited', () => {
  const createdAt = '2026-10-04T12:00:00Z'

  it('is false when the key expires as requested', () => {
    expect(expirationWasLimited({ created_at: createdAt, expires_at: '2027-01-02T12:00:00Z' }, 90)).toBe(false)
    expect(expirationWasLimited({ created_at: createdAt, expires_at: null }, null)).toBe(false)
  })

  it('is true when the API capped the expiration', () => {
    expect(expirationWasLimited({ created_at: createdAt, expires_at: '2026-10-05T12:00:00Z' }, 30)).toBe(true)
    expect(expirationWasLimited({ created_at: createdAt, expires_at: '2026-10-05T12:00:00Z' }, null)).toBe(true)
  })
})

describe('API keys page as member', () => {
  beforeEach(() => useAuthStore().setSession(user, org('member')))

  it('lists every key with its status, without secrets or management actions', async () => {
    repository.list.mockResolvedValue([active, revoked, expired])

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('ERP produção')
    expect(text).toContain('pb_AbCdEfGh1234_…')
    expect(text).toContain('Ativa')
    expect(text).toContain('Revogada')
    expect(text).toContain('Expirada')
    expect(text).toContain('Nunca usada')
    expect(text).toContain('Usuário removido')
    expect(text).toContain('somente owners criam e revogam')
    expect(text).not.toContain('Nova chave')
    expect(wrapper.findAll('button').map(button => button.text())).not.toContain('Revogar')
    wrapper.unmount()
  })

  it('shows the integration summary with placeholders only', async () => {
    repository.list.mockResolvedValue([])

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('Nenhuma API Key criada')
    expect(text).toContain('http://localhost:8000/api/v1/ingest/transactions')
    expect(text).toContain('Authorization: Bearer $PULSEBOARD_API_KEY')
    expect(text).toMatch(/409[\s\S]*transaction_conflict/)
    expect(text).toContain('Retry-After')
    expect(text).not.toMatch(/pb_[A-Za-z0-9]{12}_[A-Za-z0-9]{40}/)
    wrapper.unmount()
  })
})

describe('API keys page as owner', () => {
  beforeEach(() => useAuthStore().setSession(user, org('owner')))

  it('reveals the new key once and forgets it when the dialog closes', async () => {
    const listed: ApiKey = { ...active, id: 'k4', name: 'Nova integração', prefix: 'NeWkEy123456', last_used_at: null }
    repository.list.mockResolvedValueOnce([active]).mockResolvedValue([listed, active])
    repository.create.mockResolvedValue(created())

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    await clickButton('Nova chave')
    await type(field('Nome'), '  Nova integração  ')
    await clickButton('Criar chave')

    expect(repository.create).toHaveBeenCalledWith({ name: 'Nova integração', expires_in_days: 90 })
    expect(dialogText()).toContain('Esta é a única vez que a chave aparece.')
    expect((document.getElementById('api-key-secret') as HTMLInputElement).value).toBe(SECRET)
    expect([...document.querySelectorAll('button')].some(button => button.textContent?.includes('Copiar chave'))).toBe(true)
    expect(repository.list).toHaveBeenCalledTimes(2)

    await clickButton('Já guardei a chave')
    await vi.waitFor(() => expect(document.getElementById('api-key-secret')).toBeNull())

    expect(document.body.innerHTML).not.toContain(SECRET)
    expect(JSON.stringify(useNuxtApp().payload.data)).not.toContain(SECRET)
    expect(JSON.stringify({ ...localStorage })).not.toContain(SECRET)
    expect(JSON.stringify({ ...sessionStorage })).not.toContain(SECRET)
    expect(wrapper.text()).toContain('Nova integração')
    wrapper.unmount()
  })

  it('copies the key to the clipboard', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } })
    repository.list.mockResolvedValue([])
    repository.create.mockResolvedValue(created())

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    await clickButton('Nova chave')
    await type(field('Nome'), 'Nova integração')
    await clickButton('Criar chave')
    await clickButton('Copiar chave')

    expect(writeText).toHaveBeenCalledWith(SECRET)
    expect(dialogText()).toContain('Copiado')
    vi.unstubAllGlobals()
    wrapper.unmount()
  })

  it('tells when the organization shortened the requested expiration', async () => {
    repository.list.mockResolvedValue([])
    repository.create.mockResolvedValue(created({ created_at: '2026-10-04T12:00:00Z', expires_at: '2026-10-05T12:00:00Z' }))

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    await clickButton('Nova chave')
    await type(field('Nome'), 'Teste demo')
    await clickButton('Criar chave')

    expect(dialogText()).toContain('05/10/2026 09:00')
    expect(dialogText()).toContain('A validade foi limitada pela organização')
    wrapper.unmount()
  })

  it('shows the active key limit as a form error', async () => {
    repository.list.mockResolvedValue([active])
    repository.create.mockRejectedValue(new ApiError(422, {
      message: 'This organization already has 10 active API keys. Revoke one before creating another.',
      errors: { api_keys: ['This organization already has 10 active API keys. Revoke one before creating another.'] },
    }))

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    await clickButton('Nova chave')
    await type(field('Nome'), 'Mais uma')
    await clickButton('Criar chave')

    expect(dialogText()).toContain('Esta organização já tem 10 chaves ativas. Revogue uma antes de criar outra.')
    expect(document.getElementById('api-key-secret')).toBeNull()
    wrapper.unmount()
  })

  it('maps a 422 on the name to the field', async () => {
    repository.list.mockResolvedValue([])
    repository.create.mockRejectedValue(new ApiError(422, {
      message: 'The name field is required.',
      errors: { name: ['The name field is required.'] },
    }))

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    await clickButton('Nova chave')
    await clickButton('Criar chave')

    expect(field('Nome').getAttribute('aria-invalid')).toBe('true')
    expect(dialogText()).toContain('Campo obrigatório.')
    wrapper.unmount()
  })

  it('explains a 403 when the role changed in the meantime', async () => {
    repository.list.mockResolvedValue([])
    repository.create.mockRejectedValue(new ApiError(403, { message: 'This action requires the owner role.' }))

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    await clickButton('Nova chave')
    await type(field('Nome'), 'Sem permissão')
    await clickButton('Criar chave')

    expect(dialogText()).toContain('Somente owners podem executar esta ação.')
    wrapper.unmount()
  })

  it('revokes an active key after confirmation', async () => {
    repository.list.mockResolvedValueOnce([active, revoked]).mockResolvedValue([{ ...active, status: 'revoked' }, revoked])
    repository.revoke.mockResolvedValue(undefined)

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    const revokeButtons = wrapper.findAll('button').filter(button => button.text() === 'Revogar')
    expect(revokeButtons).toHaveLength(1)

    await revokeButtons[0]!.trigger('click')
    await flushPromises()
    expect(dialogText()).toContain('Revogar a chave “ERP produção”?')
    expect(dialogText()).toContain('pb_AbCdEfGh1234_…')
    expect(repository.revoke).not.toHaveBeenCalled()

    await clickButton('Revogar chave')

    expect(repository.revoke).toHaveBeenCalledWith('k1')
    await vi.waitFor(() => expect(repository.list).toHaveBeenCalledTimes(2))
    wrapper.unmount()
  })

  it('shows an error state with the request id when the list fails', async () => {
    repository.list.mockRejectedValue(new ApiError(500, null, undefined, { requestId: 'req-87654321' }))

    const wrapper = await mountSuspended(ApiKeysPage, { route: '/settings/api-keys', attachTo: document.body })
    await flushPromises()

    expect(wrapper.text()).toContain('O servidor encontrou um erro')
    expect(wrapper.text()).toContain('req-87654321')
    wrapper.unmount()
  })
})
