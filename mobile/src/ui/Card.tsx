import type { ReactNode } from 'react';
import { Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { ChevronRight } from './icons';
import { tap } from './haptics';
import { T } from './Text';
import { radius, space, useTheme } from './theme';

export function Card({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  const { c, dark } = useTheme();
  return (
    <View
      style={[
        styles.card,
        { backgroundColor: c.surface, borderColor: c.line },
        !dark && { shadowColor: '#101432', shadowOpacity: 0.07, shadowRadius: 16, shadowOffset: { width: 0, height: 6 }, elevation: 2 },
        style,
      ]}
    >
      {children}
    </View>
  );
}

/** A settings-style row: icon, label, optional value or control, chevron when tappable. */
export function Row({ icon, label, detail, right, onPress, last }: { icon?: ReactNode; label: string; detail?: string; right?: ReactNode; onPress?: () => void; last?: boolean }) {
  const { c } = useTheme();
  const body = (
    <View style={[styles.row, !last && { borderBottomColor: c.line, borderBottomWidth: StyleSheet.hairlineWidth }]}>
      {icon ? <View style={[styles.rowIcon, { backgroundColor: c.brandSoft }]}>{icon}</View> : null}
      <View style={{ flex: 1 }}>
        <T variant="label">{label}</T>
        {detail ? <T variant="caption" tone="muted" style={{ marginTop: 2 }}>{detail}</T> : null}
      </View>
      {right}
      {onPress && !right ? <ChevronRight size={20} color={c.muted} /> : null}
    </View>
  );
  if (!onPress) return body;
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={label} onPress={() => { tap(); onPress(); }} style={({ pressed }) => pressed && { opacity: 0.6 }}>
      {body}
    </Pressable>
  );
}

export function SectionLabel({ children }: { children: string }) {
  return <T variant="overline" tone="muted" style={{ marginTop: space.xxl, marginBottom: space.sm, marginLeft: 4 }}>{children.toUpperCase()}</T>;
}

export function EmptyState({ icon, title, body }: { icon: ReactNode; title: string; body?: string }) {
  const { c } = useTheme();
  return (
    <Card style={{ alignItems: 'center', paddingVertical: 40 }}>
      <View style={[styles.emptyIcon, { backgroundColor: c.brandSoft }]}>{icon}</View>
      <T variant="headline" style={{ textAlign: 'center' }}>{title}</T>
      {body ? <T tone="muted" style={{ textAlign: 'center', marginTop: 6, maxWidth: 320 }}>{body}</T> : null}
    </Card>
  );
}

const styles = StyleSheet.create({
  card: { borderRadius: radius.lg, borderWidth: StyleSheet.hairlineWidth, padding: space.lg },
  row: { flexDirection: 'row', alignItems: 'center', gap: space.md, paddingVertical: 14, minHeight: 56 },
  rowIcon: { width: 36, height: 36, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  emptyIcon: { width: 64, height: 64, borderRadius: 20, alignItems: 'center', justifyContent: 'center', marginBottom: 16 },
});
