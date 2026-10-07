/** Response shapes of the PulseBoard API (`docs/api.md`). */

export interface HealthResponse {
  status: 'ok' | 'degraded';
  database: 'ok' | 'error';
}
