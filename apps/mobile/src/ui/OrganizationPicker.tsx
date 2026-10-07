import { Pressable, StyleSheet, View } from 'react-native';

import type { Organization } from '@/api/types';

import { Text } from './Text';
import { colors, radius, spacing } from './theme';

const ROLE_LABELS = { owner: 'Proprietário', member: 'Membro' } as const;

interface OrganizationPickerProps {
  organizations: Organization[];
  activeId: string | null;
  onSelect: (organizationId: string) => void;
}

export function OrganizationPicker({ organizations, activeId, onSelect }: OrganizationPickerProps) {
  return (
    <View style={styles.list} accessibilityRole="radiogroup">
      {organizations.map((organization) => {
        const active = organization.id === activeId;

        return (
          <Pressable
            key={organization.id}
            accessibilityRole="radio"
            accessibilityState={{ checked: active }}
            accessibilityLabel={organization.name}
            onPress={() => onSelect(organization.id)}
            style={({ pressed }) => [styles.row, active && styles.active, pressed && styles.pressed]}
          >
            <Text variant="label">{organization.name}</Text>
            <Text variant="caption" tone="muted">
              {[organization.role ? ROLE_LABELS[organization.role] : null, organization.currency, organization.timezone]
                .filter(Boolean)
                .join(' · ')}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  list: {
    gap: spacing.sm,
  },
  row: {
    gap: spacing.xs,
    padding: spacing.lg,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  active: {
    borderColor: colors.primary,
    backgroundColor: colors.primarySoft,
  },
  pressed: {
    opacity: 0.7,
  },
});
