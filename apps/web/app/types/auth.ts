export type OrganizationRole = 'owner' | 'member'

export interface User {
  id: string
  name: string
  email: string
}

/** `OrganizationResource`; `role` is present when loaded through the user's membership. */
export interface Organization {
  id: string
  name: string
  slug: string
  currency: string
  timezone: string
  role?: OrganizationRole
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

export interface LoginInput {
  email: string
  password: string
}

export interface RegisterInput {
  name: string
  email: string
  password: string
  password_confirmation: string
}
