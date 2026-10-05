// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { ApiError, errorMessage, parseRetryAfter, translateApiMessage } from '~/utils/api-error'

describe('ApiError', () => {
  it('exposes validation field errors', () => {
    const error = new ApiError(422, { message: 'The given data was invalid.', errors: { sku: ['taken'] } })

    expect(error.isValidation).toBe(true)
    expect(error.fieldErrors).toEqual({ sku: ['taken'] })
  })

  it('detects organization context failures', () => {
    expect(new ApiError(403, { message: 'You do not have access to this organization.' }).isOrganizationContext).toBe(true)
    expect(new ApiError(400, { message: 'The X-Organization-Id header is required.' }).isOrganizationContext).toBe(true)
    expect(new ApiError(403, { message: 'This action requires the owner role.' }).isOrganizationContext).toBe(false)
  })
})

describe('error messages', () => {
  it('translates known API messages', () => {
    expect(translateApiMessage('The provided credentials are incorrect.')).toBe('E-mail ou senha incorretos.')
    expect(translateApiMessage('This action requires the owner role.')).toBe('Somente owners podem executar esta ação.')
    expect(translateApiMessage('Something unexpected.')).toBe('Something unexpected.')
  })

  it('maps statuses to user-facing messages', () => {
    expect(errorMessage(new ApiError(0, null))).toMatch(/conectar/)
    expect(errorMessage(new ApiError(429, { message: 'Too Many Attempts.' }))).toMatch(/Muitas tentativas/)
    expect(errorMessage(new ApiError(500, null))).toMatch(/servidor/)
    expect(errorMessage(new ApiError(404, { message: 'Not found' }))).toBe('Registro não encontrado.')
    expect(errorMessage(new Error('boom'), 'Falhou')).toBe('Falhou')
  })

  it('tells how long to wait when the API sends Retry-After', () => {
    expect(errorMessage(new ApiError(429, { message: 'Too many requests.', code: 'rate_limited' }, undefined, { retryAfter: 42 })))
      .toBe('Muitas tentativas. Tente novamente em 42 segundos.')
    expect(errorMessage(new ApiError(429, null, undefined, { retryAfter: 1 })))
      .toBe('Muitas tentativas. Tente novamente em 1 segundo.')
  })

  it('explains that a forbidden operation needs a permission', () => {
    expect(errorMessage(new ApiError(403, { message: 'This action requires the owner role.' })))
      .toBe('Somente owners podem executar esta ação.')
    expect(errorMessage(new ApiError(403, { message: 'Forbidden by a rule the UI does not know.' })))
      .toBe('Você não tem permissão para esta operação.')
  })

  it('translates API key and external id validation messages', () => {
    expect(translateApiMessage('This organization already has 10 active API keys. Revoke one before creating another.'))
      .toBe('Esta organização já tem 10 chaves ativas. Revogue uma antes de criar outra.')
    expect(translateApiMessage('The external id may only contain letters, numbers, dots, underscores, colons and hyphens.'))
      .toMatch(/apenas letras, números/)
    expect(translateApiMessage('This external id is already used by another customer in this organization, including deleted customers.'))
      .toMatch(/outro cliente/)
  })

  it('exposes the error code, retry delay and request id', () => {
    const error = new ApiError(429, { message: 'Too many requests.', code: 'rate_limited' }, undefined, { retryAfter: 30, requestId: 'req-1' })

    expect(error.code).toBe('rate_limited')
    expect(error.isRateLimited).toBe(true)
    expect(error.retryAfter).toBe(30)
    expect(error.requestId).toBe('req-1')
  })
})

describe('parseRetryAfter', () => {
  it('reads delta-seconds and HTTP dates', () => {
    const now = Date.parse('2026-10-04T12:00:00Z')

    expect(parseRetryAfter('60', now)).toBe(60)
    expect(parseRetryAfter('Sun, 04 Oct 2026 12:00:30 GMT', now)).toBe(30)
    expect(parseRetryAfter('Sun, 04 Oct 2026 11:59:00 GMT', now)).toBe(0)
  })

  it('ignores missing or malformed values', () => {
    expect(parseRetryAfter(null)).toBeNull()
    expect(parseRetryAfter('')).toBeNull()
    expect(parseRetryAfter('soon')).toBeNull()
  })
})
