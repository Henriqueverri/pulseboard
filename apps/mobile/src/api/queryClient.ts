import { QueryClient } from '@tanstack/react-query';

import { isApiError } from './errors';

const MAX_RETRIES = 2;

/** Retries only what a retry can fix: network failures and 5xx. A 4xx is an answer. */
export function shouldRetry(failureCount: number, error: unknown): boolean {
  if (failureCount >= MAX_RETRIES || !isApiError(error)) {
    return false;
  }

  return (error.isNetwork && !error.timedOut) || error.isServerError;
}

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: shouldRetry,
        staleTime: 30_000,
      },
      mutations: {
        retry: false,
      },
    },
  });
}
