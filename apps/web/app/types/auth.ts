export interface User {
  id: string
  name: string
  email: string
}

export interface Organization {
  id: string
  name: string
  slug: string
  currency: string
  role?: string
}

export interface AuthPayload {
  user: User
  organization: Organization | null
}

export interface MePayload {
  user: User
  organizations: Organization[]
  current_organization: Organization | null
}

export interface ApiErrorBody {
  message?: string
  errors?: Record<string, string[]>
}

export class ApiError extends Error {
  status: number
  body: ApiErrorBody | null

  constructor(status: number, body: ApiErrorBody | null, message?: string) {
    super(message || body?.message || `Request failed with status ${status}`)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }
}
