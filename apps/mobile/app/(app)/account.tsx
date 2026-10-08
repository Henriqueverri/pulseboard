import Constants from 'expo-constants';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { API_URL } from '@/config';
import { useActiveOrganization, useSession } from '@/session/SessionProvider';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Notice } from '@/ui/Notice';
import { organizationDetails, OrganizationPicker } from '@/ui/OrganizationPicker';
import { Screen } from '@/ui/Screen';
import { Text } from '@/ui/Text';
import { spacing } from '@/ui/theme';

const APP_VERSION = Constants.expoConfig?.version ?? '—';
const API_HOST = API_URL.replace(/^https?:\/\//, '').split('/')[0];

export default function AccountScreen() {
  const { user, organizations, selectOrganization, signOut } = useSession();
  const organization = useActiveOrganization();
  const [signingOut, setSigningOut] = useState(false);
  const [switchedTo, setSwitchedTo] = useState<string | null>(null);

  async function handleSignOut() {
    setSigningOut(true);
    await signOut();
  }

  async function handleSelect(id: string) {
    if (id === organization.id) {
      return;
    }

    await selectOrganization(id);
    setSwitchedTo(organizations.find((candidate) => candidate.id === id)?.name ?? null);
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
        <Text tone="muted">{organizationDetails(organization)}</Text>
      </Card>

      {switchedTo ? <Notice message={`Agora você está vendo os dados de ${switchedTo}.`} tone="info" /> : null}

      {organizations.length > 1 ? (
        <View style={styles.section}>
          <Text variant="label" accessibilityRole="header">
            Trocar de organização
          </Text>
          <OrganizationPicker
            organizations={organizations}
            activeId={organization.id}
            onSelect={(id) => void handleSelect(id)}
          />
        </View>
      ) : null}

      <Button label="Sair da conta" variant="secondary" onPress={() => void handleSignOut()} loading={signingOut} />

      <Text variant="caption" tone="muted" style={styles.footer}>
        PulseBoard {APP_VERSION} · {API_HOST}
      </Text>
    </Screen>
  );
}

const styles = StyleSheet.create({
  section: {
    gap: spacing.sm,
  },
  footer: {
    textAlign: 'center',
  },
});
