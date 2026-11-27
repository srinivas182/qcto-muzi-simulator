import type { ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaView, type Edge } from 'react-native-safe-area-context';
import { PlatformFooter } from './PlatformFooter';
import { T } from './Text';
import { space, useTheme } from './theme';

/**
 * Every screen is built on this: safe areas, keyboard handling, pull-to-refresh, a readable width
 * on tablets, and the locked MCS footer at the end of the content (never covered by tabs).
 */
export function Screen({
  title, subtitle, children, header, refreshing, onRefresh, edges = ['top', 'left', 'right'], center, bottom,
}: {
  title?: string;
  subtitle?: string;
  children: ReactNode;
  header?: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  edges?: Edge[];
  center?: boolean;
  /** Pinned under the scroll area (e.g. a primary action above the keyboard). */
  bottom?: ReactNode;
}) {
  const { c } = useTheme();
  return (
    <SafeAreaView edges={edges} style={[styles.root, { backgroundColor: c.bg }]}>
      <KeyboardAvoidingView style={styles.root} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
          contentInsetAdjustmentBehavior="automatic"
          refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={c.brand} colors={[c.brand]} /> : undefined}
        >
          {header}
          <View style={[styles.content, center && styles.center]}>
            {title ? (
              <View style={styles.titles}>
                <T variant="display" accessibilityRole="header">{title}</T>
                {subtitle ? <T tone="muted" style={{ marginTop: 6 }}>{subtitle}</T> : null}
              </View>
            ) : null}
            {children}
          </View>
          <PlatformFooter />
        </ScrollView>
        {bottom ? <View style={[styles.bottom, { backgroundColor: c.bg, borderTopColor: c.line }]}>{bottom}</View> : null}
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  scroll: { flexGrow: 1 },
  content: { flexGrow: 1, width: '100%', maxWidth: 640, alignSelf: 'center', paddingHorizontal: space.xl, paddingTop: space.lg },
  center: { justifyContent: 'center' },
  titles: { marginBottom: space.xxl },
  bottom: { paddingHorizontal: space.xl, paddingVertical: space.md, borderTopWidth: StyleSheet.hairlineWidth },
});
