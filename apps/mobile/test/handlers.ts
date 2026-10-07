import { http, HttpResponse } from 'msw';

import { DASHBOARD, REVENUE } from './fixtures';
import { apiUrl } from './server';

/** Read-only screens every signed-in flow lands on; tests override them with `server.use(...)`. */
export const defaultHandlers = [
  http.get(apiUrl('/dashboard'), () => HttpResponse.json(DASHBOARD)),
  http.get(apiUrl('/analytics/revenue'), () => HttpResponse.json(REVENUE)),
];
