import type { DashboardMetrics } from '@/api/types';

import { describeChange, formatChange, quickSummary } from '../comparison';

function metrics(overrides: Partial<DashboardMetrics> = {}): DashboardMetrics {
  return {
    revenue: { value: '12500.00', previous: '10200.00', change: 22.5 },
    orders: { value: 98, previous: 81, change: 21 },
    average_order_value: { value: '127.55', previous: '125.93', change: 1.3 },
    customers: { value: 52, previous: 47, change: 10.6 },
    ...overrides,
  };
}

describe('describeChange', () => {
  it('describes growth, decline, stability and missing base', () => {
    expect(describeChange({ value: 1, previous: 1, change: 22.5 })).toMatchObject({ trend: 'up', tone: 'positive', text: '+22,5%' });
    expect(describeChange({ value: 1, previous: 1, change: -3 })).toMatchObject({ trend: 'down', tone: 'negative', text: '−3,0%' });
    expect(describeChange({ value: 0, previous: 0, change: 0 })).toMatchObject({ trend: 'flat', tone: 'neutral', text: '0,0%' });
    expect(describeChange({ value: '10.00', previous: '0.00', change: null })).toMatchObject({ trend: 'none', text: '—' });
  });

  it('gives screen readers a full sentence', () => {
    expect(describeChange({ value: 1, previous: 1, change: -12.5 }).label).toBe('Queda de 12,5% em relação ao período anterior.');
  });

  it('formats signed changes', () => {
    expect(formatChange(100)).toBe('+100,0%');
  });
});

describe('quickSummary', () => {
  it('summarizes revenue, its trend and the number of sales', () => {
    expect(quickSummary(metrics(), 'BRL', 'nos últimos 30 dias')).toBe(
      'R$ 12.500,00 nos últimos 30 dias, 22,5% acima do período anterior, com 98 vendas.',
    );
  });

  it('calls changes below 1% stable', () => {
    const stable = metrics({ revenue: { value: '100.00', previous: '100.50', change: -0.5 } });

    expect(quickSummary(stable, 'BRL', 'hoje')).toBe('R$ 100,00 hoje, estável em relação ao período anterior, com 98 vendas.');
  });

  it('handles declines, no comparison base, a single sale and no sales', () => {
    const decline = metrics({ revenue: { value: '80.00', previous: '100.00', change: -20 } });
    const noBase = metrics({
      revenue: { value: '50.00', previous: '0.00', change: null },
      orders: { value: 1, previous: 0, change: null },
    });
    const none = metrics({ orders: { value: 0, previous: 3, change: -100 } });

    expect(quickSummary(decline, 'BRL', 'neste mês')).toBe('R$ 80,00 neste mês, 20,0% abaixo do período anterior, com 98 vendas.');
    expect(quickSummary(noBase, 'BRL', 'hoje')).toBe('R$ 50,00 hoje, sem base de comparação com o período anterior, com 1 venda.');
    expect(quickSummary(none, 'BRL', 'nos últimos 7 dias')).toBe('Nenhuma venda nos últimos 7 dias.');
  });
});
