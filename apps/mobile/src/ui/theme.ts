export const colors = {
  background: '#F6F7FB',
  surface: '#FFFFFF',
  border: '#E3E6EE',
  text: '#111827',
  textMuted: '#5B6475',
  primary: '#4F46E5',
  primaryPressed: '#4338CA',
  primarySoft: '#EEF0FF',
  onPrimary: '#FFFFFF',
  positive: '#047857',
  positiveSoft: '#E7F6EF',
  negative: '#B91C1C',
  negativeSoft: '#FDECEC',
  warning: '#B45309',
  warningSoft: '#FEF3E2',
  neutralSoft: '#EEF0F4',
} as const;

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

export const radius = {
  sm: 8,
  md: 12,
  lg: 16,
  pill: 999,
} as const;

export const typography = {
  title: { fontSize: 26, lineHeight: 32, fontWeight: '700' },
  heading: { fontSize: 18, lineHeight: 24, fontWeight: '600' },
  body: { fontSize: 15, lineHeight: 21, fontWeight: '400' },
  label: { fontSize: 14, lineHeight: 19, fontWeight: '600' },
  caption: { fontSize: 13, lineHeight: 18, fontWeight: '400' },
} as const;
