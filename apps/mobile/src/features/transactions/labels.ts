import type { TransactionSource, TransactionStatus } from '@/api/types';

/** Same order and wording as the web app. */
export const TRANSACTION_STATUSES: readonly TransactionStatus[] = ['paid', 'refunded', 'pending', 'canceled'];

export const STATUS_LABELS: Record<TransactionStatus, string> = {
  paid: 'Pago',
  refunded: 'Reembolsado',
  pending: 'Pendente',
  canceled: 'Cancelado',
};

export const SOURCE_LABELS: Record<TransactionSource, string> = {
  seed: 'Demo',
  ingest: 'Integração',
};
