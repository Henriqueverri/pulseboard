import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { useActiveOrganization, useSession } from '@/session/SessionProvider';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { OrganizationPicker } from '@/ui/OrganizationPicker';
import { Screen } from '@/ui/Screen';
import { Text } from '@/ui/Text';
import { spacing } from '@/ui/theme';

export default function AccountScreen() {
  const { user, organizations, selectOrganization, signOut } = useSession();
  const organization = useActiveOrganization();
  const [signingOut, setSigningOut] = useState(false);

  async function handleSignOut() {
    setSigningOut(true);
    await signOut();
  }

  return (
    <Screen scroll edges={['left', 'right']}>
      <Card>
        <Text variant="caption" tone="muted">
          Usuário
        </Text>
        <Text variant="heading">{user?.name}</Text>
        <Text tone="muted">{user?.email}</Text>
      </Card>

      <Card>
        <Text variant="caption" tone="muted">
          Organização atual
        </Text>
        <Text variant="heading">{organization.name}</Text>
        <Text tone="muted">
          {organization.currency} · {organization.timezone}
        </Text>
      </Card>

      {organizations.length > 1 ? (
        <View style={styles.section}>
          <Text variant="label">Trocar de organização</Text>
          <OrganizationPicker
            organizations={organizations}
            activeId={organization.id}
            onSelect={(id) => void selectOrganization(id)}
          />
        </View>
      ) : null}

      <Button label="Sair da conta" variant="secondary" onPress={() => void handleSignOut()} loading={signingOut} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  section: {
    gap: spacing.sm,
  },
});
