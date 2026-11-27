import { Redirect } from 'expo-router';
import { ActivityIndicator, View } from 'react-native';
import { useAcademy } from '../src/lib/academy';
import { useAuth } from '../src/lib/auth';
import { useTheme } from '../src/ui';

/** Start-up: academy chosen? signed in? Then straight to the right place. */
export default function Start() {
  const { academy, ready: academyReady } = useAcademy();
  const { token, ready } = useAuth();
  const { c } = useTheme();

  if (!academyReady || !ready) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: c.bg }}>
        <ActivityIndicator color={c.brand} />
      </View>
    );
  }
  if (!academy) return <Redirect href="/welcome" />;
  if (!token) return <Redirect href="/sign-in" />;
  return <Redirect href="/home" />;
}
