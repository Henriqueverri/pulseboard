import { useQuery } from '@tanstack/react-query';

import { getHealth } from '@/api/health';
import { API_URL } from '@/config';
import { Button } from '@/ui/Button';
import { ErrorState } from '@/ui/ErrorState';
import { LoadingState } from '@/ui/LoadingState';
import { Screen } from '@/ui/Screen';
import { Text } from '@/ui/Text';

/** Temporary foundation screen: proves the app reaches the API before login exists. */
export default function HealthScreen() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: ({ signal }) => getHealth(signal),
  });

  return (
    <Screen scroll>
      <Text variant="title">PulseBoard</Text>
      <Text tone="muted">API: {API_URL}</Text>

      {health.isPending ? <LoadingState label="Verificando a API…" /> : null}

      {health.isError ? (
        <ErrorState
          title="API indisponível"
          error={health.error}
          onRetry={() => health.refetch()}
          retrying={health.isFetching}
        />
      ) : null}

      {health.isSuccess ? (
        <>
          <Text variant="heading" tone={health.data.status === 'ok' ? 'positive' : 'negative'}>
            Status: {health.data.status}
          </Text>
          <Text tone="muted">Banco de dados: {health.data.database}</Text>
          <Button label="Verificar novamente" variant="secondary" onPress={() => health.refetch()} loading={health.isFetching} />
        </>
      ) : null}
    </Screen>
  );
}
