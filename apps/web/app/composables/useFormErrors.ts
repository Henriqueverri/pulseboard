import { ApiError, errorMessage, translateApiMessage } from '~/utils/api-error'

/**
 * Maps API failures onto a form: 422 messages go next to their fields and anything else
 * (credentials, throttling, network, 5xx, unknown fields) becomes a form-level message.
 */
export function useFormErrors<Field extends string>(fields: readonly Field[]) {
  const fieldErrors = ref<Partial<Record<Field, string>>>({})
  const formError = ref<string | null>(null)

  function reset() {
    fieldErrors.value = {}
    formError.value = null
  }

  function clear(field: Field) {
    if (fieldErrors.value[field]) {
      fieldErrors.value = Object.fromEntries(
        Object.entries(fieldErrors.value).filter(([key]) => key !== field),
      ) as Partial<Record<Field, string>>
    }
  }

  function capture(error: unknown, fallback = 'Não foi possível concluir. Tente novamente.') {
    reset()

    if (!(error instanceof ApiError)) {
      console.error(error)
      formError.value = fallback

      return
    }

    if (!error.isValidation) {
      formError.value = errorMessage(error, fallback)

      return
    }

    const unmatched: string[] = []
    const next: Partial<Record<Field, string>> = {}

    for (const [field, messages] of Object.entries(error.fieldErrors)) {
      const message = messages[0] ? translateApiMessage(messages[0]) : null
      if (!message) {
        continue
      }

      if ((fields as readonly string[]).includes(field)) {
        next[field as Field] = message
      }
      else {
        unmatched.push(message)
      }
    }

    fieldErrors.value = next

    if (unmatched.length > 0) {
      formError.value = unmatched.join(' ')
    }
    else if (Object.keys(next).length === 0) {
      formError.value = errorMessage(error, fallback)
    }
  }

  /** Client-side check that the API cannot express (e.g. an amount the user typed but we cannot parse). */
  function setFieldError(field: Field, message: string) {
    fieldErrors.value = { ...fieldErrors.value, [field]: message }
  }

  return {
    fieldErrors,
    formError,
    capture,
    clear,
    reset,
    setFieldError,
  }
}
