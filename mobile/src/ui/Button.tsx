import { useRef, type ReactNode } from 'react';
import { ActivityIndicator, Animated, Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { tap } from './haptics';
import { T } from './Text';
import { radius, useTheme } from './theme';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

/** Large, thumb-friendly button: springs on press and gives a light vibration. */
export function Button({
  title, onPress, variant = 'primary', loading, disabled, icon, style, accessibilityHint,
}: {
  title: string;
  onPress: () => void;
  variant?: Variant;
  loading?: boolean;
  disabled?: boolean;
  icon?: ReactNode;
  style?: StyleProp<ViewStyle>;
  accessibilityHint?: string;
}) {
  const { c } = useTheme();
  const scale = useRef(new Animated.Value(1)).current;
  // Always call the latest handler, even if a press starts before the newest render has been applied.
  const handler = useRef(onPress);
  handler.current = onPress;
  const spring = (to: number) => Animated.spring(scale, { toValue: to, useNativeDriver: true, speed: 40, bounciness: 6 }).start();
  const off = disabled || loading;

  const bg = { primary: c.brand, secondary: c.surface, ghost: 'transparent', danger: c.danger }[variant];
  const fg = variant === 'primary' || variant === 'danger' ? '#FFFFFF' : variant === 'ghost' ? c.brand : c.text;

  return (
    <Animated.View style={[{ transform: [{ scale }] }, style]}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={title}
        accessibilityHint={accessibilityHint}
        accessibilityState={{ disabled: !!off, busy: !!loading }}
        disabled={off}
        onPressIn={() => spring(0.97)}
        onPressOut={() => spring(1)}
        onPress={() => {
          tap();
          handler.current();
        }}
        style={[
          styles.base,
          { backgroundColor: bg, opacity: off ? 0.55 : 1 },
          variant === 'secondary' && { borderWidth: StyleSheet.hairlineWidth * 2, borderColor: c.line },
        ]}
      >
        {loading ? <ActivityIndicator color={fg} /> : icon ? <View style={styles.icon}>{icon}</View> : null}
        <T variant="label" style={{ color: fg, fontSize: 16 }}>{title}</T>
      </Pressable>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  base: { minHeight: 54, borderRadius: radius.md, paddingHorizontal: 20, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 10 },
  icon: { marginRight: 2 },
});
