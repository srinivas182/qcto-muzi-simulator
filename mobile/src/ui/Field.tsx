import { forwardRef, useState, type ComponentRef } from 'react';
import { StyleSheet, TextInput, View, type TextInputProps } from 'react-native';
import { T } from './Text';
import { radius, useTheme } from './theme';

export const Field = forwardRef<ComponentRef<typeof TextInput>, TextInputProps & { label: string; hint?: string; error?: string; big?: boolean }>(function Field(
  { label, hint, error, big, style, onFocus, onBlur, ...rest },
  ref,
) {
  const { c } = useTheme();
  const [focused, setFocused] = useState(false);
  return (
    <View style={styles.wrap}>
      <T variant="caption" style={{ color: c.text, fontWeight: '700', marginBottom: 6 }}>{label}</T>
      <TextInput
        ref={ref}
        accessibilityLabel={label}
        accessibilityHint={error ?? hint}
        placeholderTextColor={c.muted}
        selectionColor={c.brand}
        onFocus={(e) => { setFocused(true); onFocus?.(e); }}
        onBlur={(e) => { setFocused(false); onBlur?.(e); }}
        style={[
          styles.input,
          big && styles.big,
          { color: c.text, backgroundColor: c.surface, borderColor: error ? c.danger : focused ? c.brand : c.line },
          focused && { shadowColor: c.brand, shadowOpacity: 0.25, shadowRadius: 8, shadowOffset: { width: 0, height: 0 } },
          style,
        ]}
        {...rest}
      />
      {error ? <T variant="caption" tone="danger" style={styles.note} accessibilityLiveRegion="polite">{error}</T> : hint ? <T variant="caption" tone="muted" style={styles.note}>{hint}</T> : null}
    </View>
  );
});

const styles = StyleSheet.create({
  wrap: { marginBottom: 16 },
  input: { minHeight: 54, borderWidth: 1.5, borderRadius: radius.md, paddingHorizontal: 16, fontSize: 17 },
  big: { fontSize: 28, fontWeight: '700', letterSpacing: 10, textAlign: 'center' },
  note: { marginTop: 6 },
});
