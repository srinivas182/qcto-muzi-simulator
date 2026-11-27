import { useEffect, useRef, type ReactNode } from 'react';
import { Animated, Modal, PanResponder, Pressable, StyleSheet, View, useWindowDimensions } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { T } from './Text';
import { radius, space, useTheme } from './theme';

/** Bottom sheet: slides up, drag down or tap outside to close. Used instead of pop-up dialogs. */
export function Sheet({ open, onClose, title, children }: { open: boolean; onClose: () => void; title: string; children: ReactNode }) {
  const { c } = useTheme();
  const insets = useSafeAreaInsets();
  const { height } = useWindowDimensions();
  const y = useRef(new Animated.Value(height)).current;
  const fade = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    if (!open) return;
    y.setValue(height);
    Animated.parallel([
      Animated.spring(y, { toValue: 0, useNativeDriver: true, damping: 22, stiffness: 220 }),
      Animated.timing(fade, { toValue: 1, duration: 180, useNativeDriver: true }),
    ]).start();
  }, [open, height, y, fade]);

  const close = () =>
    Animated.parallel([
      Animated.timing(y, { toValue: height, duration: 200, useNativeDriver: true }),
      Animated.timing(fade, { toValue: 0, duration: 200, useNativeDriver: true }),
    ]).start(() => onClose());

  const drag = useRef(
    PanResponder.create({
      onMoveShouldSetPanResponder: (_, g) => g.dy > 6,
      onPanResponderMove: (_, g) => g.dy > 0 && y.setValue(g.dy),
      onPanResponderRelease: (_, g) => {
        if (g.dy > 120 || g.vy > 1.2) close();
        else Animated.spring(y, { toValue: 0, useNativeDriver: true, damping: 22, stiffness: 220 }).start();
      },
    }),
  ).current;

  return (
    <Modal visible={open} transparent animationType="none" onRequestClose={close} statusBarTranslucent>
      <Animated.View style={[StyleSheet.absoluteFill, { backgroundColor: c.overlay, opacity: fade }]}>
        <Pressable style={StyleSheet.absoluteFill} onPress={close} accessibilityLabel="Close" />
      </Animated.View>
      <Animated.View
        accessibilityViewIsModal
        style={[styles.sheet, { backgroundColor: c.surface, paddingBottom: insets.bottom + space.lg, transform: [{ translateY: y }] }]}
      >
        <View {...drag.panHandlers} style={styles.handleArea}>
          <View style={[styles.handle, { backgroundColor: c.line }]} />
          <T variant="headline" accessibilityRole="header">{title}</T>
        </View>
        <View style={{ paddingHorizontal: space.xl }}>{children}</View>
      </Animated.View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  sheet: { position: 'absolute', left: 0, right: 0, bottom: 0, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl, maxWidth: 640, alignSelf: 'center', width: '100%' },
  handleArea: { alignItems: 'center', paddingTop: 10, paddingBottom: space.lg },
  handle: { width: 40, height: 5, borderRadius: 3, marginBottom: 14 },
});
