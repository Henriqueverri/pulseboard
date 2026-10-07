import { useActiveOrganization, useSession } from '@/session/SessionProvider';
import { Screen } from '@/ui/Screen';
import { Text } from '@/ui/Text';

export default function HomeScreen() {
  const { user } = useSession();
  const organization = useActiveOrganization();

  return (
    <Screen edges={['left', 'right']}>
      <Text variant="title">Olá, {user?.name}</Text>
      <Text tone="muted">{organization.name}</Text>
    </Screen>
  );
}
