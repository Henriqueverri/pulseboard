import { Pressable, ScrollView, StyleSheet, View } from 'react-native';

import { Text } from './Text';
import { colors, radius, spacing } from './theme';

export interface ChipOption<T> {
  value: T;
  label: string;
}

interface ChipGroupProps<T> {
  /** Announced by screen readers as the name of the radio group. */
  label: string;
  options: readonly ChipOption<T>[];
  value: T;
  onChange: (value: T) => void;
  /** Few short options share the width; longer sets scroll horizontally instead of wrapping. */
  scrollable?: boolean;
}

/** Single-choice filter rendered as a row of chips. */
export function ChipGroup<T extends string | null>({ label, options, value, onChange, scrollable = false }: ChipGroupProps<T>) {
  const chips = options.map((option) => {
    const selected = option.value === value;

    return (
      <Pressable
        key={option.label}
        accessibilityRole="radio"
        accessibilityState={{ checked: selected }}
        accessibilityLabel={option.label}
        onPress={() => onChange(option.value)}
        hitSlop={4}
        style={[styles.chip, !scrollable && styles.fill, selected && styles.selected]}
      >
        <Text variant="caption" style={[styles.label, selected && styles.selectedLabel]}>
          {option.label}
        </Text>
      </Pressable>
    );
  });

  if (scrollable) {
    return (
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.row}
        accessibilityRole="radiogroup"
        accessibilityLabel={label}
      >
        {chips}
      </ScrollView>
    );
  }

  return (
    <View style={styles.row} accessibilityRole="radiogroup" accessibilityLabel={label}>
      {chips}
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  chip: {
    minHeight: 36,
    paddingHorizontal: spacing.md,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  fill: {
    flex: 1,
    paddingHorizontal: 0,
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
