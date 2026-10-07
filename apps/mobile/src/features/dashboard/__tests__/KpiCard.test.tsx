import { render, screen } from '@testing-library/react-native';

import { KpiCard } from '../KpiCard';

describe('<KpiCard />', () => {
  it('shows a positive change', async () => {
    await render(<KpiCard label="Receita" metric={{ value: '1', previous: '1', change: 22.5 }} value="R$ 12.500,00" previous="R$ 10.200,00" />);

    expect(screen.getByText('▲ +22,5%')).toBeOnTheScreen();
    expect(screen.getByText('antes R$ 10.200,00')).toBeOnTheScreen();
    expect(
      screen.getByLabelText('Receita: R$ 12.500,00. Aumento de 22,5% em relação ao período anterior. Anterior: R$ 10.200,00.'),
    ).toBeOnTheScreen();
  });

  it('shows a negative change', async () => {
    await render(<KpiCard label="Vendas" metric={{ value: 5, previous: 10, change: -50 }} value="5" previous="10" />);

    expect(screen.getByText('▼ −50,0%')).toBeOnTheScreen();
  });

  it('shows no comparison when change is null', async () => {
    await render(<KpiCard label="Ticket médio" metric={{ value: null, previous: null, change: null }} value="—" previous="—" />);

    // Value and badge.
    expect(screen.getAllByText('—')).toHaveLength(2);
    expect(screen.getByText('antes —')).toBeOnTheScreen();
    expect(screen.getByLabelText(/Sem base de comparação/)).toBeOnTheScreen();
  });
});
