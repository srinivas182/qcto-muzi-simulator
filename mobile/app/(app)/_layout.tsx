import { Redirect, Tabs } from 'expo-router';
import { View } from 'react-native';
import { BarChart3, Box, CircleUserRound, GraduationCap, House } from '../../src/ui/icons';
import { useAuth } from '../../src/lib/auth';
import { useI18n } from '../../src/lib/i18n';
import { isLearner } from '../../src/lib/roles';
import { ConsentSheet } from '../../src/screens/ConsentSheet';
import { OfflineBanner, useTheme } from '../../src/ui';
import { TabBar } from '../../src/ui/TabBar';

/** Signed-in area. Learners get Home · Simulate · Courses · Progress · Me; staff get Home · Me. */
export default function AppLayout() {
  const { token, ready, session } = useAuth();
  const { t } = useI18n();
  const { c } = useTheme();

  if (ready && !token) return <Redirect href="/sign-in" />;
  const learner = session ? isLearner(session) : true;
  const hide = (show: boolean) => (show ? {} : { href: null });

  return (
    <View style={{ flex: 1, backgroundColor: c.bg }}>
      <OfflineBanner />
      <Tabs tabBar={(props) => <TabBar {...props} />} screenOptions={{ headerShown: false, sceneStyle: { backgroundColor: c.bg } }}>
        <Tabs.Screen name="home" options={{ title: t('nav.learn'), tabBarIcon: ({ color }) => <House size={22} color={color} /> }} />
        <Tabs.Screen name="simulate" options={{ title: t('nav.learn.simulate'), tabBarIcon: ({ color }) => <Box size={22} color={color} />, ...hide(learner) }} />
        <Tabs.Screen name="courses" options={{ title: t('nav.learn.courses'), tabBarIcon: ({ color }) => <GraduationCap size={22} color={color} />, ...hide(learner) }} />
        <Tabs.Screen name="progress" options={{ title: t('nav.learn.progress'), tabBarIcon: ({ color }) => <BarChart3 size={22} color={color} />, ...hide(learner) }} />
        <Tabs.Screen name="me" options={{ title: t('m.tabs.me'), tabBarIcon: ({ color }) => <CircleUserRound size={22} color={color} /> }} />
      </Tabs>
      {session && session.user.consent_required.length > 0 ? <ConsentSheet purposes={session.user.consent_required} /> : null}
    </View>
  );
}
