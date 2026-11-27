import { Pressable, StyleSheet, View } from 'react-native';
import { router } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { Box, GraduationCap, PlayCircle, Sparkles } from '../src/ui/icons';
import { LOCALES, type Locale } from '@simuwork/core';
import { useI18n } from '../src/lib/i18n';
import { Button, haptics, radius, Screen, space, T, useTheme } from '../src/ui';

/** First open: choose a language (before anything else), then find the academy. */
export default function Welcome() {
  const { t, locale, setLocale } = useI18n();
  const { c } = useTheme();

  return (
    <Screen
      edges={['left', 'right', 'bottom']}
      header={
        <LinearGradient colors={[c.nav, c.brand]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
          <View style={styles.tiles} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
            {[Box, PlayCircle, GraduationCap, Sparkles].map((Icon, i) => (
              <View key={i} style={[styles.tile, { transform: [{ translateY: i % 2 ? -14 : 10 }, { rotate: `${(i - 1.5) * 5}deg` }] }]}>
                <Icon size={30} color="#fff" />
              </View>
            ))}
          </View>
        </LinearGradient>
      }
      bottom={<Button title={t('m.welcome.start')} onPress={() => router.push('/academy')} />}
    >
      <T variant="display" accessibilityRole="header">{t('m.welcome.title')}</T>
      <T tone="muted" style={{ marginTop: space.md }}>{t('m.welcome.body')}</T>

      <View style={styles.langs} accessibilityRole="radiogroup" accessibilityLabel={t('common.language')}>
        {(Object.keys(LOCALES) as Locale[]).map((l) => {
          const active = l === locale;
          return (
            <Pressable
              key={l}
              accessibilityRole="radio"
              accessibilityState={{ checked: active }}
              onPress={() => { haptics.select(); setLocale(l); }}
              style={[styles.lang, { borderColor: active ? c.brand : c.line, backgroundColor: active ? c.brandSoft : c.surface }]}
            >
              <T variant="label" style={{ color: active ? c.brand : c.text }}>{LOCALES[l]}</T>
            </Pressable>
          );
        })}
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  hero: { height: 300, alignItems: 'center', justifyContent: 'center', borderBottomLeftRadius: radius.xl, borderBottomRightRadius: radius.xl, marginBottom: space.lg },
  tiles: { flexDirection: 'row', gap: 14, marginTop: 40 },
  tile: { width: 64, height: 64, borderRadius: 20, backgroundColor: 'rgba(255,255,255,0.14)', borderColor: 'rgba(255,255,255,0.22)', borderWidth: 1, alignItems: 'center', justifyContent: 'center' },
  langs: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: space.xxl },
  lang: { paddingHorizontal: 18, minHeight: 44, justifyContent: 'center', borderRadius: radius.pill, borderWidth: 1.5 },
});
