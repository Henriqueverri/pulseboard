/**
 * Remembers the selected organization between reloads. It only holds an organization id
 * (the API validates membership on every request), never credentials.
 */
export const ORGANIZATION_COOKIE = 'pb_org'

const ONE_YEAR = 60 * 60 * 24 * 365
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

export function readOrganizationCookie(): string | null {
  if (typeof document === 'undefined') {
    return null
  }

  const match = document.cookie.match(new RegExp(`(?:^|; )${ORGANIZATION_COOKIE}=([^;]*)`))
  const value = match?.[1] ? decodeURIComponent(match[1]) : null

  return value && UUID.test(value) ? value : null
}

export function writeOrganizationCookie(organizationId: string | null): void {
  if (typeof document === 'undefined') {
    return
  }

  const secure = location.protocol === 'https:' ? '; Secure' : ''

  document.cookie = organizationId
    ? `${ORGANIZATION_COOKIE}=${encodeURIComponent(organizationId)}; Path=/; Max-Age=${ONE_YEAR}; SameSite=Lax${secure}`
    : `${ORGANIZATION_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax${secure}`
}
