import { Linking, StyleSheet, Text } from 'react-native';

/**
 * Platform-owned footer for the mobile app. PLATFORM RULE: shown on every tenant screen and
 * never changeable by a training provider. Keep text and URL identical to packages/ui
 * (MCS_FOOTER); Sprint 1.5 moves both into one shared module.
 */
export const MCS_FOOTER = {
  prefix: 'Developed and Maintained by',
  company: 'Mayura Consultancy Services',
  url: 'https://www.mayuraconsultancy.com',
} as const;

export function PlatformFooter() {
  return (
    <Text style={styles.footer} accessibilityRole="text">
      {MCS_FOOTER.prefix}{' '}
      <Text style={styles.link} accessibilityRole="link" onPress={() => Linking.openURL(MCS_FOOTER.url)}>
        {MCS_FOOTER.company}
      </Text>
    </Text>
  );
}

const styles = StyleSheet.create({
  footer: { textAlign: 'center', fontSize: 12, color: '#6B7090', paddingVertical: 12 },
  link: { color: '#4B45D6', fontWeight: '600' },
});
