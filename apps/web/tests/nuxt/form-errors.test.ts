import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '~/utils/api-error'
import { useFormErrors } from '~/composables/useFormErrors'

describe('useFormErrors', () => {
  const fields = ['name', 'email', 'password'] as const

  it('puts translated 422 messages next to their fields', () => {
    const { fieldErrors, formError, capture } = useFormErrors(fields)

    capture(new ApiError(422, {
      message: 'The email has already been taken. (and 1 more error)',
      errors: {
        email: ['The email has already been taken.'],
        password: ['The password field must be at least 8 characters.'],
      },
    }))

    expect(fieldErrors.value).toEqual({
      email: 'Este e-mail já está cadastrado.',
      password: 'A senha deve ter pelo menos 8 caracteres.',
    })
    expect(formError.value).toBeNull()
  })

  it('maps the login credentials error to the email field', () => {
    const { fieldErrors, capture } = useFormErrors(fields)

    capture(new ApiError(422, {
      message: 'The provided credentials are incorrect.',
      errors: { email: ['The provided credentials are incorrect.'] },
    }))

    expect(fieldErrors.value.email).toBe('E-mail ou senha incorretos.')
  })

  it('translates generic Laravel rules', () => {
    const { fieldErrors, capture } = useFormErrors(fields)

    capture(new ApiError(422, {
      message: 'The name field is required.',
      errors: {
        name: ['The name field is required.'],
        email: ['The email field must be a valid email address.'],
        password: ['The password field must not be greater than 255 characters.'],
      },
    }))

    expect(fieldErrors.value).toEqual({
      name: 'Campo obrigatório.',
      email: 'Informe um e-mail válido.',
      password: 'Use no máximo 255 caracteres.',
    })
  })

  it('shows errors for fields the form does not have at form level', () => {
    const { fieldErrors, formError, capture } = useFormErrors(fields)

    capture(new ApiError(422, {
      message: 'The organization id field is prohibited.',
      errors: { organization_id: ['The organization id field is prohibited.'] },
    }))

    expect(fieldErrors.value).toEqual({})
    expect(formError.value).toBe('The organization id field is prohibited.')
  })

  it.each([
    [new ApiError(429, { message: 'Too Many Attempts.' }), 'Muitas tentativas. Aguarde um minuto e tente novamente.'],
    [new ApiError(0, null, 'Network error'), 'Não foi possível conectar à API. Verifique sua conexão.'],
    [new ApiError(500, { message: 'Server Error' }), 'O servidor encontrou um erro. Tente novamente em instantes.'],
    [new ApiError(403, { message: 'This action requires the owner role.' }), 'Somente owners podem executar esta ação.'],
  ])('shows non-validation failures at form level (%#)', (error, message) => {
    const { formError, capture } = useFormErrors(fields)

    capture(error)

    expect(formError.value).toBe(message)
  })

  it('reports unexpected errors instead of swallowing them', () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
    const { formError, capture } = useFormErrors(fields)
    const bug = new TypeError('boom')

    capture(bug, 'Falhou.')

    expect(formError.value).toBe('Falhou.')
    expect(consoleError).toHaveBeenCalledWith(bug)
  })

  it('clears one field or everything', () => {
    const { fieldErrors, formError, capture, clear, reset } = useFormErrors(fields)
    capture(new ApiError(422, { message: 'x', errors: { name: ['The name field is required.'], email: ['The email field is required.'] } }))

    clear('name')
    expect(Object.keys(fieldErrors.value)).toEqual(['email'])

    reset()
    expect(fieldErrors.value).toEqual({})
    expect(formError.value).toBeNull()
  })
})
