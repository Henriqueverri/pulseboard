// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { ApiError, errorMessage, translateApiMessage } from '~/utils/api-error'

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
})
