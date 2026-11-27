import { useState } from 'react';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client';
import { AcademyProvider } from '../src/lib/academy';
import { AuthProvider } from '../src/lib/auth';
import { I18nProvider } from '../src/lib/i18n';
import { createQueryClient, PERSIST_MAX_AGE, persister } from '../src/lib/query';
import { SettingsProvider } from '../src/lib/settings';
import { ThemeProvider, useTheme } from '../src/ui';

function Navigator() {
  const { c, dark } = useTheme();
  return (
    <>
      <StatusBar style={dark ? 'light' : 'dark'} />
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: c.bg }, animation: 'slide_from_right' }}>
        <Stack.Screen name="scan" options={{ presentation: 'fullScreenModal', animation: 'slide_from_bottom' }} />
      </Stack>
    </>
  );
}

export default function RootLayout() {
  const [client] = useState(createQueryClient);
  return (
    <SafeAreaProvider>
      <PersistQueryClientProvider client={client} persistOptions={{ persister, maxAge: PERSIST_MAX_AGE }}>
        <SettingsProvider>
          <I18nProvider>
            <AcademyProvider>
              <AuthProvider>
                <ThemeProvider>
                  <Navigator />
                </ThemeProvider>
              </AuthProvider>
            </AcademyProvider>
          </I18nProvider>
        </SettingsProvider>
      </PersistQueryClientProvider>
    </SafeAreaProvider>
  );
}
