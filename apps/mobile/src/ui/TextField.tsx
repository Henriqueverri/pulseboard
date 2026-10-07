import { forwardRef } from 'react';
import { StyleSheet, TextInput, View, type TextInputProps } from 'react-native';

import { Text } from './Text';
import { colors, radius, spacing, typography } from './theme';

interface TextFieldProps extends TextInputProps {
  label: string;
  error?: string | null;
}

export const TextField = forwardRef<TextInput, TextFieldProps>(function TextField({ label, error, style, ...props }, ref) {
  return (
    <View style={styles.container}>
      <Text variant="label">{label}</Text>
      <TextInput
        ref={ref}
        accessibilityLabel={label}
        placeholderTextColor={colors.textMuted}
        style={[styles.input, error ? styles.inputError : null, style]}
        {...props}
      />
      {error ? (
        <Text variant="caption" tone="negative" accessibilityLiveRegion="polite">
          {error}
        </Text>
      ) : null}
    </View>
  );
});

const styles = StyleSheet.create({
  container: {
    gap: spacing.xs,
  },
  input: {
    ...typography.body,
    minHeight: 48,
    paddingHorizontal: spacing.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    color: colors.text,
  },
  inputError: {
    borderColor: colors.negative,
  },
});
