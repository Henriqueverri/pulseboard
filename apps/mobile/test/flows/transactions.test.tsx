import { router, type Href } from 'expo-router';
import { act, fireEvent, renderRouter, screen } from 'expo-router/testing-library';
import { http, HttpResponse, type HttpResponseResolver } from 'msw';

import type { Paginated, Transaction } from '@/api/types';
import { presetRange } from '@/lib/period';

import {
  demoStore,
  INGESTED_TRANSACTION,
  profile,
  secondStore,
  transaction,
  transactionId,
  transactionsPage,
} from '../fixtures';
import { apiUrl, server } from '../server';
import { storeSession } from '../session';

type PageResolver = (params: URLSearchParams) => Paginated<Transaction> | Response;

/** Serves `GET /transactions` and records every query string and tenant header it receives. */
function serveList(resolve: PageResolver) {
  const requests: { params: URLSearchParams; organization: string | null }[] = [];

  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json(profile())),
    http.get(apiUrl('/transactions'), ({ request }) => {
      const params = new URL(request.url).searchParams;
      requests.push({ params, organization: request.headers.get('x-organization-id') });
      const result = resolve(params);

      return result instanceof Response ? result : HttpResponse.json(result);
    }),
  );

  return requests;
}

function serveDetail(response: HttpResponseResolver<{ id: string }>) {
  server.use(
    http.get(apiUrl('/auth/me'), () => HttpResponse.json(profile())),
    http.get(apiUrl('/transactions/:id'), response),
  );
}

/** The protected stack only mounts app routes once the stored session is restored, so navigate after that. */
async function open(url: Href) {
  await storeSession();
  await renderRouter('./app', { initialUrl: '/' });
  await screen.findByText('PulseBoard Demo Store');
  await act(() => router.push(url));
}

afterEach(() => jest.useRealTimers());

describe('transaction list', () => {
  it('lists the first page of the last 30 days in the organization timezone', async () => {
    const requests = serveList(() =>
      transactionsPage(
        [transaction(1), transaction(2, { source: 'ingest', external_id: 'pos-2', status: 'pending', items_count: 1 })],
        1,
        1,
        2,
      ),
    );

    await open('/transactions');

    expect(await screen.findByText('Cliente 1')).toBeOnTheScreen();
    expect(screen.getByText('2 transações')).toBeOnTheScreen();
    expect(screen.getAllByText('15/09/2026 11:32 · 2 itens')).toHaveLength(1);
    expect(screen.getByText('15/09/2026 11:32 · 1 item')).toBeOnTheScreen();
    expect(
      screen.getByRole('button', { name: 'Cliente 2, R$ 150,00, Pendente, 15/09/2026 11:32, Integração' }),
    ).toBeOnTheScreen();
    expect(screen.getByText('Integração')).toBeOnTheScreen();
    expect(screen.getByText('Fim da lista')).toBeOnTheScreen();

    const range = presetRange('30d', demoStore.timezone);
    const params = requests[0]?.params;
    expect(params?.get('page')).toBe('1');
    expect(params?.get('per_page')).toBe('20');
    expect(params?.get('from')).toBe(range.from);
    expect(params?.get('to')).toBe(range.to);
    expect(params?.has('status')).toBe(false);
    expect(params?.has('q')).toBe(false);
    expect(requests[0]?.organization).toBe(demoStore.id);
  });

  it('loads the next page on scroll and stops at the last one', async () => {
    const requests = serveList((params) =>
      params.get('page') === '2'
        ? transactionsPage([transaction(3)], 2, 2, 3)
        : transactionsPage([transaction(1), transaction(2)], 1, 2, 3),
    );

    await open('/transactions');
    await screen.findByText('Cliente 2');
    expect(screen.queryByText('Fim da lista')).toBeNull();

    await fireEvent(screen.getByTestId('transactions-list'), 'endReached');

    expect(await screen.findByText('Cliente 3')).toBeOnTheScreen();
    expect(screen.getByText('Fim da lista')).toBeOnTheScreen();

    await fireEvent(screen.getByTestId('transactions-list'), 'endReached');

    expect(requests.map((request) => request.params.get('page'))).toEqual(['1', '2']);
  });

  it('keeps the loaded rows and offers a retry when the next page fails', async () => {
    let failNextPage = true;
    serveList((params) => {
      if (params.get('page') === '2') {
        return failNextPage
          ? HttpResponse.json({ message: 'Server Error' }, { status: 500 })
          : transactionsPage([transaction(3)], 2, 2, 3);
      }

      return transactionsPage([transaction(1), transaction(2)], 1, 2, 3);
    });

    await open('/transactions');
    await screen.findByText('Cliente 2');
    await fireEvent(screen.getByTestId('transactions-list'), 'endReached');

    expect(await screen.findByText('Não foi possível carregar mais', {}, { timeout: 10_000 })).toBeOnTheScreen();
    expect(screen.getByText('Cliente 1')).toBeOnTheScreen();

    failNextPage = false;
    await fireEvent.press(screen.getByRole('button', { name: 'Tentar novamente' }));

    expect(await screen.findByText('Cliente 3')).toBeOnTheScreen();
  });

  it('sends the status, period and search filters to the API', async () => {
    const requests = serveList((params) =>
      params.get('q') === 'ninguém' ? transactionsPage([]) : transactionsPage([transaction(1)]),
    );

    await open('/transactions');
    await screen.findByText('Cliente 1');

    await fireEvent.press(screen.getByRole('radio', { name: 'Reembolsado' }));
    await fireEvent.press(screen.getByRole('radio', { name: '7 dias' }));
    await fireEvent.changeText(screen.getByLabelText('Buscar'), '  ninguém ');
    await fireEvent(screen.getByLabelText('Buscar'), 'submitEditing');

    expect(await screen.findByText('Nenhuma transação encontrada')).toBeOnTheScreen();

    const last = requests.at(-1)?.params;
    const week = presetRange('7d', demoStore.timezone);
    expect(last?.get('status')).toBe('refunded');
    expect(last?.get('from')).toBe(week.from);
    expect(last?.get('to')).toBe(week.to);
    expect(last?.get('q')).toBe('ninguém');
    expect(last?.get('page')).toBe('1');

    // Clearing the field drops the search right away (served from the cache of the same filters).
    await fireEvent.changeText(screen.getByLabelText('Buscar'), '');

    expect(await screen.findByText('Cliente 1')).toBeOnTheScreen();
  });

  it('shows the empty state of a period without sales', async () => {
    serveList(() => transactionsPage([]));

    await open('/transactions');

    expect(await screen.findByText('Nenhuma transação no período')).toBeOnTheScreen();
    expect(screen.getByText('0 transações')).toBeOnTheScreen();
  });

  it('shows the error with the request id and retries', async () => {
    let fail = true;
    serveList(() =>
      fail
        ? HttpResponse.json({ message: 'Server Error' }, { status: 503, headers: { 'X-Request-Id': 'req-tx-503' } })
        : transactionsPage([transaction(1)]),
    );

    await open('/transactions');

    expect(await screen.findByText('ID da requisição: req-tx-503', {}, { timeout: 10_000 })).toBeOnTheScreen();

    fail = false;
    await fireEvent.press(screen.getByRole('button', { name: 'Tentar novamente' }));

    expect(await screen.findByText('Cliente 1')).toBeOnTheScreen();
  });

  it('opens the detail of a row', async () => {
    serveList(() => transactionsPage([transaction(42, { customer: INGESTED_TRANSACTION.customer })]));
    serveDetail(() => HttpResponse.json({ data: INGESTED_TRANSACTION }));

    await open('/transactions');
    await fireEvent.press(await screen.findByRole('button', { name: /^Ana Souza, R\$ 150,00/ }));

    expect(await screen.findByText('Fone Bluetooth')).toBeOnTheScreen();
  });
});

describe('transaction detail', () => {
  it('shows items, customer, identification and the full status timeline', async () => {
    let requested: { id: unknown; organization: string | null } | null = null;
    serveDetail(({ request, params }) => {
      requested = { id: params.id, organization: request.headers.get('x-organization-id') };
      return HttpResponse.json({ data: INGESTED_TRANSACTION });
    });

    await open(`/transactions/${INGESTED_TRANSACTION.id}`);

    expect(await screen.findByText('R$ 259,80')).toBeOnTheScreen();
    expect(screen.getByText('Reembolsado')).toBeOnTheScreen();
    expect(screen.getByText('Ana Souza')).toBeOnTheScreen();
    expect(screen.getByText('2 × R$ 99,90')).toBeOnTheScreen();
    expect(screen.getByText('R$ 199,80')).toBeOnTheScreen();
    expect(screen.getByText('Capa (removido)')).toBeOnTheScreen();
    expect(screen.getByText('pos-1042')).toBeOnTheScreen();
    expect(screen.getByText('Integração')).toBeOnTheScreen();

    // Lifecycle order as returned by the API; times in America/Sao_Paulo.
    const steps = screen.getAllByText(/^(Criada como|Pago →)/).map((node) => node.props.children.join(''));
    expect(steps).toEqual(['Criada como Pago', 'Pago → Reembolsado · Status atual']);
    expect(screen.getByText('15/09/2026 11:32 · Integração')).toBeOnTheScreen();
    expect(screen.getByText('16/09/2026 09:00 · Integração')).toBeOnTheScreen();
    // Only the step stored long after it happened shows the recording time.
    expect(screen.getAllByText(/^Registrada em/)).toHaveLength(1);
    expect(screen.getByText('Registrada em 16/09/2026 15:30')).toBeOnTheScreen();
    expect(requested).toEqual({ id: INGESTED_TRANSACTION.id, organization: demoStore.id });
  });

  it('explains a missing transaction and goes back to the list', async () => {
    serveList(() => transactionsPage([transaction(1)]));
    serveDetail(() => HttpResponse.json({ message: 'Resource not found.' }, { status: 404 }));

    await open('/transactions');
    await screen.findByText('Cliente 1');
    await act(() => router.push(`/transactions/${transactionId(999)}`));

    expect(await screen.findByText('Transação não encontrada')).toBeOnTheScreen();

    await fireEvent.press(screen.getByRole('button', { name: 'Voltar para transações' }));

    expect(await screen.findByText('Cliente 1')).toBeOnTheScreen();
  });

  it('goes back to login when the token expires', async () => {
    serveDetail(() => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 }));

    await open(`/transactions/${INGESTED_TRANSACTION.id}`);

    expect(await screen.findByText('Sua sessão expirou. Entre novamente.')).toBeOnTheScreen();
  });

  it('sends the user back to choose an organization when access is denied', async () => {
    serveDetail(() => HttpResponse.json({ message: 'You do not have access to this organization.' }, { status: 403 }));
    // Membership revoked after the app opened: the refreshed profile no longer lists the organization.
    let profileCalls = 0;
    server.use(
      http.get(apiUrl('/auth/me'), () => {
        profileCalls += 1;

        return HttpResponse.json(
          profileCalls === 1
            ? profile()
            : profile([secondStore, { ...secondStore, id: '9d1a0000-0000-4000-8000-000000000003', name: 'Loja Norte' }]),
        );
      }),
    );

    await open(`/transactions/${INGESTED_TRANSACTION.id}`);

    expect(await screen.findByText('Escolha a organização')).toBeOnTheScreen();
    expect(screen.getByText('Você não tem mais acesso a essa organização. Escolha outra.')).toBeOnTheScreen();
  });
});
