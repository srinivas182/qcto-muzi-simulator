import { Text as RNText, type TextProps } from 'react-native';
import { type, useTheme } from './theme';

type Variant = keyof typeof type;
type Tone = 'default' | 'muted' | 'brand' | 'inverse' | 'danger' | 'success';

export function T({ variant = 'body', tone = 'default', style, ...rest }: TextProps & { variant?: Variant; tone?: Tone }) {
  const { c } = useTheme();
  const color = { default: c.text, muted: c.muted, brand: c.brand, inverse: '#FFFFFF', danger: c.danger, success: c.success }[tone];
  return <RNText maxFontSizeMultiplier={1.6} style={[type[variant], { color }, style]} {...rest} />;
}
