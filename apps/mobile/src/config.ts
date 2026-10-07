const DEFAULT_API_URL = 'http://10.0.2.2:8000/api/v1';

/** `EXPO_PUBLIC_*` is inlined in the bundle at build time: never put a secret here. */
export const API_URL = (process.env.EXPO_PUBLIC_API_URL || DEFAULT_API_URL).replace(/\/+$/, '');

/** Default per-request timeout; the login waits longer for a cold-started API. */
export const REQUEST_TIMEOUT_MS = 15_000;
