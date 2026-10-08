import { useSession } from '@/session/SessionProvider';
import { Button } from '@/ui/Button';
import { Notice } from '@/ui/Notice';
import { OrganizationPicker } from '@/ui/OrganizationPicker';
import { Screen } from '@/ui/Screen';
import { Text } from '@/ui/Text';

export default function SelectOrganizationScreen() {
  const { user, organizations, notice, selectOrganization, signOut } = useSession();

  return (
    <Screen scroll>
      <Text variant="title" accessibilityRole="header">
        Escolha a organização
      </Text>
      {user ? <Text tone="muted">Olá, {user.name}. Você participa de mais de uma organização.</Text> : null}
      {notice ? <Notice message={notice} tone="warning" /> : null}

      {organizations.length === 0 ? (
        <Notice message="Sua conta não participa de nenhuma organização." tone="warning" />
      ) : (
        <OrganizationPicker organizations={organizations} activeId={null} onSelect={(id) => void selectOrganization(id)} />
      )}

      <Button label="Sair da conta" variant="ghost" onPress={() => void signOut()} />
    </Screen>
  );
}
