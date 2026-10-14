import Constants from 'expo-constants';
import { useState } from 'react';
import { Linking, StyleSheet, Switch, View } from 'react-native';

import { API_URL } from '@/config';
import type { PushStatus } from '@/push/pushRegistration';
import { usePushSetting } from '@/push/usePushSetting';
import { useActiveOrganization, useSession } from '@/session/SessionProvider';
import { Button } from '@/ui/Button';
import { Card } from '@/ui/Card';
import { Notice } from '@/ui/Notice';
import { organizationDetails, OrganizationPicker } from '@/ui/OrganizationPicker';
import { Screen } from '@/ui/Screen';
import { Text } from '@/ui/Text';
import { colors, spacing } from '@/ui/theme';

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

      <PushSection />

      <Button label="Sair da conta" variant="secondary" onPress={() => void handleSignOut()} loading={signingOut} />

      <Text variant="caption" tone="muted" style={styles.footer}>
        PulseBoard {APP_VERSION} · {API_HOST}
      </Text>
    </Screen>
  );
}

const PUSH_NOTICES: Record<Exclude<PushStatus, 'registered'>, { message: string; tone: 'warning' | 'error' }> = {
  denied: { message: 'As notificações estão bloqueadas para o PulseBoard. Libere nas configurações do celular.', tone: 'warning' },
  unsupported: { message: 'O Expo Go no Android não recebe notificações. Instale o APK do PulseBoard.', tone: 'warning' },
  failed: { message: 'Não foi possível atualizar as notificações. Verifique sua conexão e tente de novo.', tone: 'error' },
};

function PushSection() {
  const { enabled, status, pending, setEnabled } = usePushSetting();
  const blocked = status === 'denied' || status === 'unsupported';

  return (
    <View style={styles.section}>
      <Card>
        <View style={styles.switchRow}>
          <View style={styles.switchText}>
            <Text variant="label" nativeID="push-switch-label">
              Avisos no celular
            </Text>
            <Text variant="caption" tone="muted">
              Estoque baixo ou zerado e meta do dia batida, uma vez por dia.
            </Text>
          </View>
          <Switch
            accessibilityLabel="Avisos no celular"
            accessibilityLabelledBy="push-switch-label"
            value={enabled === true && !blocked}
            disabled={enabled === null || pending || status === 'unsupported'}
            onValueChange={(next) => void setEnabled(next)}
            trackColor={{ true: colors.primary }}
          />
        </View>
      </Card>

      {status && status !== 'registered' ? <Notice message={PUSH_NOTICES[status].message} tone={PUSH_NOTICES[status].tone} /> : null}

      {status === 'denied' ? <Button label="Abrir configurações" variant="secondary" onPress={() => void Linking.openSettings()} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  section: {
    gap: spacing.sm,
  },
  switchRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  switchText: {
    flex: 1,
    gap: spacing.xs,
  },
  footer: {
    textAlign: 'center',
  },
});
