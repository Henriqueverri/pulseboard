import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import type { TransactionStatus } from '@/api/types';
import type { PeriodPreset } from '@/lib/period';
import { ChipGroup } from '@/ui/ChipGroup';
import { PeriodSelector } from '@/ui/PeriodSelector';
import { TextField } from '@/ui/TextField';
import { spacing } from '@/ui/theme';

import { STATUS_LABELS, TRANSACTION_STATUSES } from './labels';

const STATUS_OPTIONS = [
  { value: null, label: 'Todos' },
  ...TRANSACTION_STATUSES.map((status) => ({ value: status, label: STATUS_LABELS[status] })),
];

interface TransactionFiltersProps {
  status: TransactionStatus | null;
  onStatusChange: (status: TransactionStatus | null) => void;
  preset: PeriodPreset;
  onPresetChange: (preset: PeriodPreset) => void;
  onSearch: (search: string) => void;
}

/** The search applies on submit (or when cleared), not per keystroke: one request per intent. */
export function TransactionFilters({ status, onStatusChange, preset, onPresetChange, onSearch }: TransactionFiltersProps) {
  const [draft, setDraft] = useState('');

  function onChangeText(text: string) {
    setDraft(text);

    if (text.trim() === '') {
      onSearch('');
    }
  }

  return (
    <View style={styles.container}>
      <TextField
        label="Buscar"
        placeholder="Cliente, e-mail ou ID externo"
        value={draft}
        onChangeText={onChangeText}
        onSubmitEditing={() => onSearch(draft.trim())}
        returnKeyType="search"
        autoCapitalize="none"
        autoCorrect={false}
        clearButtonMode="while-editing"
      />
      <ChipGroup<TransactionStatus | null>
        label="Status"
        options={STATUS_OPTIONS}
        value={status}
        onChange={onStatusChange}
        scrollable
      />
      <PeriodSelector value={preset} onChange={onPresetChange} />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    gap: spacing.md,
  },
});
