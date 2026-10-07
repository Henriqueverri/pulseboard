import { useMutation } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet, type TextInput } from 'react-native';

import { errorMessage, isApiError } from '@/api/errors';
import { useSession } from '@/session/SessionProvider';
import { Button } from '@/ui/Button';
import { Notice } from '@/ui/Notice';
import { Screen } from '@/ui/Screen';
import { Text } from '@/ui/Text';
import { TextField } from '@/ui/TextField';

export default function LoginScreen() {
  const { signIn, notice } = useSession();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [missingFields, setMissingFields] = useState(false);
  const passwordRef = useRef<TextInput>(null);

  const login = useMutation({ mutationFn: signIn });

  function submit() {
    if (login.isPending) {
      return;
    }

    const trimmedEmail = email.trim();
    setMissingFields(trimmedEmail === '' || password === '');

    if (trimmedEmail === '' || password === '') {
      return;
    }

    login.mutate({ email: trimmedEmail, password });
  }

  // Credential errors come back as 422 on `email`; the generic message covers the rest.
  const formError = login.isError ? errorMessage(login.error) : null;
  const passwordError = isApiError(login.error) ? login.error.fieldError('password') : null;

  return (
    <KeyboardAvoidingView style={styles.fill} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen scroll>
        <Text variant="title" accessibilityRole="header">
          PulseBoard
        </Text>
        <Text tone="muted">Entre para acompanhar as vendas da sua organização.</Text>

        {notice && !login.isError ? <Notice message={notice} tone="warning" /> : null}
        {formError ? <Notice message={formError} tone="error" /> : null}
        {missingFields ? <Notice message="Informe e-mail e senha." tone="error" /> : null}

        <TextField
          label="E-mail"
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoCorrect={false}
          autoComplete="email"
          keyboardType="email-address"
          textContentType="username"
          returnKeyType="next"
          onSubmitEditing={() => passwordRef.current?.focus()}
          editable={!login.isPending}
        />
        <TextField
          ref={passwordRef}
          label="Senha"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoComplete="current-password"
          textContentType="password"
          returnKeyType="go"
          onSubmitEditing={submit}
          error={passwordError}
          editable={!login.isPending}
        />

        <Button label="Entrar" onPress={submit} loading={login.isPending} />
      </Screen>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  fill: {
    flex: 1,
  },
});
