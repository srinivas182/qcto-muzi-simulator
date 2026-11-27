import { StatusBar } from 'expo-status-bar';
import { SafeAreaView, StyleSheet, Text, View } from 'react-native';
import { PlatformFooter } from './src/PlatformFooter';

/** Sprint 1.1 shell. Real navigation, sign-in and learner home arrive in later sprints. */
export default function App() {
  return (
    <SafeAreaView style={styles.root}>
      <View style={styles.center}>
        <View style={styles.logo}>
          <Text style={styles.logoText}>S</Text>
        </View>
        <Text style={styles.title}>SimuWork</Text>
        <Text style={styles.subtitle}>Do the job before you get the job.</Text>
      </View>
      <PlatformFooter />
      <StatusBar style="dark" />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: '#F5F6FA' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  logo: { width: 56, height: 56, borderRadius: 16, backgroundColor: '#4B45D6', alignItems: 'center', justifyContent: 'center' },
  logoText: { color: '#fff', fontSize: 26, fontWeight: '700' },
  title: { marginTop: 16, fontSize: 24, fontWeight: '700', color: '#151933' },
  subtitle: { marginTop: 6, fontSize: 15, color: '#6B7090' },
});
