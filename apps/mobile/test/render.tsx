import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react-native';
import type { ReactElement } from 'react';

/** Fresh client per test: no cache leaks between tests and no retries slowing failures down. */
export function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  });
}

export function renderWithClient(ui: ReactElement, queryClient = createTestQueryClient()) {
  return render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>);
}
