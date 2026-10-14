import { http, HttpResponse } from 'msw';

import { DASHBOARD, NO_INVENTORY, REVENUE } from './fixtures';
import { apiUrl } from './server';

/** Read-only screens every signed-in flow lands on; tests override them with `server.use(...)`. */
export const defaultHandlers = [
  http.get(apiUrl('/dashboard'), () => HttpResponse.json(DASHBOARD)),
  http.get(apiUrl('/analytics/revenue'), () => HttpResponse.json(REVENUE)),
  http.get(apiUrl('/analytics/inventory'), () => HttpResponse.json(NO_INVENTORY)),
  // The signed-in layout asks for the realtime server; flows run without one.
  http.get(apiUrl('/realtime'), () => HttpResponse.json({ data: { enabled: false } })),
  // ...and registers the device for alerts.
  http.post(apiUrl('/devices/push-token'), () => new HttpResponse(null, { status: 204 })),
  http.delete(apiUrl('/devices/push-token'), () => new HttpResponse(null, { status: 204 })),
];
