import { StyleSheet, View } from 'react-native';

import type { TransactionStatusChange } from '@/api/types';
import { formatDateTime } from '@/lib/dates';
import { Text } from '@/ui/Text';
import { colors, spacing } from '@/ui/theme';

import { SOURCE_LABELS, STATUS_LABELS } from './labels';

/** Below this gap the recording time adds nothing next to the business time (same rule as the web). */
const RECORDING_GAP_MS = 60_000;

function title(change: TransactionStatusChange): string {
  return change.from_status === null
    ? `Criada como ${STATUS_LABELS[change.to_status]}`
    : `${STATUS_LABELS[change.from_status]} → ${STATUS_LABELS[change.to_status]}`;
}

/** `status_history` rendered in the order the API returns it (lifecycle order). */
export function StatusTimeline({ history, timezone }: { history: TransactionStatusChange[]; timezone: string }) {
  if (history.length === 0) {
    return (
      <Text variant="caption" tone="muted">
        A API não retornou mudanças de status para esta transação.
      </Text>
    );
  }

  return (
    <View accessibilityLabel="Histórico de status">
      {history.map((change, index) => {
        const current = index === history.length - 1;
        const delayed = Math.abs(Date.parse(change.recorded_at) - Date.parse(change.occurred_at)) >= RECORDING_GAP_MS;

        return (
          <View key={`${change.from_status ?? 'new'}-${change.to_status}`} style={styles.step} accessible>
            <View style={styles.rail}>
              <View style={[styles.dot, current && styles.currentDot]} />
              {current ? null : <View style={styles.line} />}
            </View>
            <View style={styles.body}>
              <Text variant="label">
                {title(change)}
                {current ? ' · Status atual' : ''}
              </Text>
              <Text variant="caption" tone="muted">
                {formatDateTime(change.occurred_at, timezone)} · {SOURCE_LABELS[change.source]}
              </Text>
              {delayed ? (
                <Text variant="caption" tone="muted">
                  Registrada em {formatDateTime(change.recorded_at, timezone)}
                </Text>
              ) : null}
            </View>
          </View>
        );
      })}
    </View>
  );
}

const DOT = 12;

const styles = StyleSheet.create({
  step: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  rail: {
    alignItems: 'center',
    width: DOT,
  },
  dot: {
    width: DOT,
    height: DOT,
    marginTop: 4,
    borderRadius: DOT / 2,
    borderWidth: 2,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  currentDot: {
    borderColor: colors.primary,
    backgroundColor: colors.primary,
  },
  line: {
    flex: 1,
    width: 2,
    marginVertical: 2,
    backgroundColor: colors.border,
  },
  body: {
    flex: 1,
    gap: 2,
    paddingBottom: spacing.lg,
  },
});
