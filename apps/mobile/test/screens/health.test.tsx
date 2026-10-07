import { screen } from '@testing-library/react-native';
import { http, HttpResponse } from 'msw';

import HealthScreen from '../../app/index';
import { renderWithClient } from '../render';
import { apiUrl, server } from '../server';

describe('<HealthScreen />', () => {
  it('shows the API status', async () => {
    server.use(http.get(apiUrl('/health'), () => HttpResponse.json({ status: 'ok', database: 'ok' })));

    await renderWithClient(<HealthScreen />);

    expect(await screen.findByText('Status: ok')).toBeOnTheScreen();
    expect(screen.getByText('Banco de dados: ok')).toBeOnTheScreen();
  });

  it('shows the error with the request id and a retry', async () => {
    server.use(
      http.get(apiUrl('/health'), () =>
        HttpResponse.json(
          { status: 'degraded', database: 'error' },
          { status: 503, headers: { 'X-Request-Id': 'req-health-503' } },
        ),
      ),
    );

    await renderWithClient(<HealthScreen />);

    expect(await screen.findByText('API indisponível')).toBeOnTheScreen();
    expect(screen.getByText('ID da requisição: req-health-503')).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Tentar novamente' })).toBeOnTheScreen();
  });
});
