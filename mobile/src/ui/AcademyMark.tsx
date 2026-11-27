import { Image, View } from 'react-native';
import { T } from './Text';
import { useTheme } from './theme';

export function AcademyMark({ name, logo, size = 44 }: { name: string; logo?: string | null; size?: number }) {
  const { c } = useTheme();
  if (logo) return <Image source={{ uri: logo }} accessibilityIgnoresInvertColors style={{ width: size, height: size, borderRadius: size * 0.28, backgroundColor: '#fff' }} resizeMode="contain" />;
  return (
    <View style={{ width: size, height: size, borderRadius: size * 0.28, backgroundColor: c.brand, alignItems: 'center', justifyContent: 'center' }}>
      <T style={{ color: '#fff', fontSize: size * 0.45, fontWeight: '800' }}>{(name || 'S').slice(0, 1).toUpperCase()}</T>
    </View>
  );
}
