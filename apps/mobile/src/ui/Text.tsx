import { Text as NativeText, type TextProps as NativeTextProps } from 'react-native';

import { colors, typography } from './theme';

export interface TextProps extends NativeTextProps {
  variant?: keyof typeof typography;
  tone?: 'default' | 'muted' | 'primary' | 'positive' | 'negative';
}

const TONES = {
  default: colors.text,
  muted: colors.textMuted,
  primary: colors.primary,
  positive: colors.positive,
  negative: colors.negative,
} as const;

export function Text({ variant = 'body', tone = 'default', style, ...props }: TextProps) {
  return <NativeText {...props} style={[typography[variant], { color: TONES[tone] }, style]} />;
}
