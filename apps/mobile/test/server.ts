import { setupServer } from 'msw/node';

import { API_URL } from '@/config';

/** MSW server shared by every test; handlers are added per test with `server.use(...)`. */
export const server = setupServer();

export function apiUrl(path: string): string {
  return `${API_URL}${path}`;
}
