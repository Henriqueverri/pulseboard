import { fireEvent, renderRouter, screen } from 'expo-router/testing-library';
import { http, HttpResponse } from 'msw';

import type { DashboardResponse } from '@/api/types';
import { presetRange } from '@/lib/period';

import { DASHBOARD, demoStore, EMPTY_DASHBOARD, profile, REVENUE, secondStore } from '../fixtures';
import { apiUrl, server } from '../server';
import { storeSession } from '../session';

interface Captured {
  url: URL;
  organization: string | null;
}

function serve(dashboard: DashboardResponse = DASHBOARD, organizations = [demoStore]) {
  const requests: Captured[] = [];
  const capture = (request: Request) =>
    requests.push({ url: new URL(request.url), organization: request.headers.get('x-organization-id') });

  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json(profile(organizations))),
    http.get(apiUrl('/dashboard'), ({ request }) => {
      capture(request);
      return HttpResponse.json(dashboard);
    }),
    http.get(apiUrl('/analytics/revenue'), ({ request }) => {
      capture(request);
      return HttpResponse.json(REVENUE);
    }),
  );

  return requests;
}

async function openDashboard() {
  await renderRouter('./app', { initialUrl: '/' });
}

afterEach(() => jest.useRealTimers());

describe('dashboard', () => {
  it('shows the KPIs, the summary and the chart for the default 30-day period', async () => {
    await storeSession();
    const requests = serve();

    await openDashboard();

    expect(await screen.findByText('R$ 12.500,00 nos últimos 30 dias, 22,5% acima do período anterior, com 98 vendas.')).toBeOnTheScreen();
    expect(screen.getByText('R$ 12.500,00')).toBeOnTheScreen();
    expect(screen.getByText('98')).toBeOnTheScreen();
    expect(screen.getByText('R$ 127,55')).toBeOnTheScreen();
    expect(screen.getByText('01 – 30 set 2026 · comparado a 02 – 31 ago 2026')).toBeOnTheScreen();
    expect(await screen.findByText('Melhor dia: R$ 900,00 em 02/09')).toBeOnTheScreen();

    // from/to computed in the organization's timezone; every request carries the tenant header.
    const expected = presetRange('30d', demoStore.timezone);
    const dashboardRequest = requests.find((request) => request.url.pathname.endsWith('/dashboard'));
    const revenueRequest = requests.find((request) => request.url.pathname.endsWith('/analytics/revenue'));

    expect(dashboardRequest?.url.searchParams.get('from')).toBe(expected.from);
    expect(dashboardRequest?.url.searchParams.get('to')).toBe(expected.to);
    expect(revenueRequest?.url.searchParams.get('granularity')).toBe('day');
    expect(requests.every((request) => request.organization === demoStore.id)).toBe(true);
  });

  it('requests the selected period and hides the chart for "today"', async () => {
    await storeSession();
    const requests = serve();

    await openDashboard();
    await screen.findByText('Melhor dia: R$ 900,00 em 02/09');
    requests.length = 0;

    await fireEvent.press(screen.getByRole('radio', { name: 'Hoje' }));

    expect(await screen.findByText('R$ 12.500,00 hoje, 22,5% acima do período anterior, com 98 vendas.')).toBeOnTheScreen();
    const today = presetRange('today', demoStore.timezone);
    expect(requests.map((request) => request.url.pathname.replace(/.*\/api\/v1/, ''))).toEqual(['/dashboard']);
    expect(requests[0]?.url.searchParams.get('from')).toBe(today.from);
    expect(screen.queryByText(/Receita diária/)).toBeNull();
  });

  it('shows the empty state when the period has no sales', async () => {
    await storeSession();
    serve(EMPTY_DASHBOARD);

    await openDashboard();

    expect(await screen.findByText('Nenhuma venda nos últimos 30 dias.')).toBeOnTheScreen();
    expect(await screen.findByText('Sem vendas no período')).toBeOnTheScreen();
  });

  it('shows the error with the request id and retries', async () => {
    await storeSession();
    serve();
    server.use(
      http.get(apiUrl('/dashboard'), () =>
        HttpResponse.json({ message: 'Server Error' }, { status: 500, headers: { 'X-Request-Id': 'req-dash-500' } }),
      ),
    );

    await openDashboard();

    // Network failures and 5xx are retried twice before the error shows.
    expect(await screen.findByText('ID da requisição: req-dash-500', {}, { timeout: 10_000 })).toBeOnTheScreen();
    expect(screen.getByText('O servidor encontrou um erro. Tente novamente em instantes.')).toBeOnTheScreen();

    serve();
    await fireEvent.press(screen.getByRole('button', { name: 'Tentar novamente' }));

    expect(await screen.findByText('R$ 12.500,00')).toBeOnTheScreen();
  });

  it('goes back to login when the token expires', async () => {
    await storeSession();
    serve();
    server.use(http.get(apiUrl('/dashboard'), () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })));

    await openDashboard();

    expect(await screen.findByText('Sua sessão expirou. Entre novamente.')).toBeOnTheScreen();
  });

  it('refetches with the new tenant header after switching organizations', async () => {
    await storeSession();
    const requests = serve(DASHBOARD, [demoStore, secondStore]);

    await openDashboard();
    await screen.findByText('R$ 12.500,00');
    await fireEvent.press(screen.getByText('Conta'));
    const option = await screen.findByRole('radio', { name: secondStore.name });
    // Current organization card and its row in the picker.
    expect(screen.getAllByText('Proprietário · BRL · America/Sao_Paulo')).toHaveLength(2);
    requests.length = 0;
    await fireEvent.press(option);
    expect(await screen.findByText('Agora você está vendo os dados de Loja Centro.')).toBeOnTheScreen();
    await fireEvent.press(screen.getByText('Início'));

    expect(await screen.findByText(secondStore.name)).toBeOnTheScreen();
    await screen.findByText('R$ 12.500,00');
    expect(requests.length).toBeGreaterThan(0);
    expect(requests.every((request) => request.organization === secondStore.id)).toBe(true);
  });
});
