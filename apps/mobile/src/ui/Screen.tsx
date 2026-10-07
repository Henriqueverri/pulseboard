import type { ReactNode } from 'react';
import { ScrollView, StyleSheet, View, type RefreshControlProps } from 'react-native';
import { SafeAreaView, type Edge } from 'react-native-safe-area-context';

import { colors, spacing } from './theme';

interface ScreenProps {
  children: ReactNode;
  /** Scrollable content (with optional pull-to-refresh); lists use their own FlatList instead. */
  scroll?: boolean;
  refreshControl?: React.ReactElement<RefreshControlProps>;
  /** Screens under a native header or tab bar only need the remaining edges. */
  edges?: Edge[];
}

export function Screen({ children, scroll = false, refreshControl, edges = ['top', 'left', 'right'] }: ScreenProps) {
  return (
    <SafeAreaView style={styles.safeArea} edges={edges}>
      {scroll ? (
        <ScrollView
          contentContainerStyle={styles.content}
          refreshControl={refreshControl}
          keyboardShouldPersistTaps="handled"
        >
          {children}
        </ScrollView>
      ) : (
        <View style={[styles.content, styles.fill]}>{children}</View>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
    backgroundColor: colors.background,
  },
  content: {
    padding: spacing.lg,
    gap: spacing.lg,
  },
  fill: {
    flex: 1,
  },
});
