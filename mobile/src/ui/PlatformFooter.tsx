import { Linking, StyleSheet, Text } from 'react-native';
import { MCS_FOOTER } from '@simuwork/core';
import { useTheme } from './theme';

/**
 * PLATFORM RULE: on every screen, never changeable by an academy. Wording comes from
 * packages/core (same as web); colours are platform-owned, never the academy brand.
 * Rendered by <Screen>, which every route uses; test/footer.test.tsx checks every route.
 */
export function PlatformFooter() {
  const { c } = useTheme();
  return (
    <Text testID="platform-footer" style={[styles.footer, { color: c.footerText }]} accessibilityRole="text" maxFontSizeMultiplier={1.4}>
      {MCS_FOOTER.prefix}{' '}
      <Text
        style={[styles.link, { color: c.footerLink }]}
        accessibilityRole="link"
        accessibilityHint={MCS_FOOTER.url}
        onPress={() => void Linking.openURL(MCS_FOOTER.url)}
      >
        {MCS_FOOTER.company}
      </Text>
    </Text>
  );
}

const styles = StyleSheet.create({
  footer: { textAlign: 'center', fontSize: 12, lineHeight: 18, paddingTop: 20, paddingBottom: 12, paddingHorizontal: 16 },
  link: { fontWeight: '700' },
});
