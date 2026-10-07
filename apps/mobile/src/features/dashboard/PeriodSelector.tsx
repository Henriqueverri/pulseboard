import { Pressable, StyleSheet, View } from 'react-native';

import { PERIOD_PRESETS, PRESET_LABELS, type PeriodPreset } from '@/lib/period';
import { Text } from '@/ui/Text';
import { colors, radius, spacing } from '@/ui/theme';

interface PeriodSelectorProps {
  value: PeriodPreset;
  onChange: (preset: PeriodPreset) => void;
}

export function PeriodSelector({ value, onChange }: PeriodSelectorProps) {
  return (
    <View style={styles.row} accessibilityRole="radiogroup" accessibilityLabel="Período">
      {PERIOD_PRESETS.map((preset) => {
        const selected = preset === value;

        return (
          <Pressable
            key={preset}
            accessibilityRole="radio"
            accessibilityState={{ checked: selected }}
            accessibilityLabel={PRESET_LABELS[preset]}
            onPress={() => onChange(preset)}
            hitSlop={4}
            style={[styles.chip, selected && styles.selected]}
          >
            <Text variant="caption" style={[styles.label, selected && styles.selectedLabel]}>
              {PRESET_LABELS[preset]}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  chip: {
    flex: 1,
    minHeight: 36,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  selected: {
    borderColor: colors.primary,
    backgroundColor: colors.primary,
  },
  label: {
    fontWeight: '600',
    color: colors.text,
  },
  selectedLabel: {
    color: colors.onPrimary,
  },
});
