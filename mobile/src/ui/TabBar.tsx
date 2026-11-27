import { Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import type { BottomTabBarProps } from 'expo-router/tabs';
import { select } from './haptics';
import { T } from './Text';
import { useTheme } from './theme';

/** Thumb-first tab bar: big targets, a soft brand pill on the active tab, a tick of vibration on change. */
export function TabBar({ state, descriptors, emitter, navigateToTab }: BottomTabBarProps) {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  // Tabs hidden with `href: null` (e.g. learning tabs for staff) are flagged `hidden` by Expo Router.
  const visible = state.routes.filter((r) => !(descriptors[r.key]?.options as { hidden?: boolean } | undefined)?.hidden);

  return (
    <View accessibilityRole="tablist" style={[styles.bar, { backgroundColor: c.surface, borderTopColor: c.line, paddingBottom: Math.max(insets.bottom, 8) }]}>
      {visible.map((route) => {
        const options = descriptors[route.key]!.options;
        const focused = state.routes[state.index]?.key === route.key;
        const label = typeof options.title === 'string' ? options.title : route.name;
        const color = focused ? c.brand : c.muted;
        return (
          <Pressable
            key={route.key}
            accessibilityRole="tab"
            accessibilityState={{ selected: focused }}
            accessibilityLabel={label}
            onPress={() => {
              const event = emitter.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
              if (!focused && !event.defaultPrevented) {
                select();
                navigateToTab(route.key);
              }
            }}
            style={styles.tab}
          >
            <View style={[styles.pill, focused && { backgroundColor: c.brandSoft }]}>{options.tabBarIcon?.({ focused, color, size: 22 })}</View>
            <T variant="caption" style={{ color, fontWeight: focused ? '800' : '600', fontSize: 12 }} numberOfLines={1}>{label}</T>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  bar: { flexDirection: 'row', borderTopWidth: StyleSheet.hairlineWidth, paddingTop: 8 },
  tab: { flex: 1, alignItems: 'center', gap: 3, minHeight: 52 },
  pill: { width: 56, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center' },
});
