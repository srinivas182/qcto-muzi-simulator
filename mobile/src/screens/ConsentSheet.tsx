import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { useQueryClient } from '@tanstack/react-query';
import { Check } from '../ui/icons';
import { api } from '../lib/api';
import { useI18n } from '../lib/i18n';
import { Button, haptics, radius, Sheet, space, T, useTheme } from '../ui';

/** POPIA: the app cannot be used until the current consents are given. */
export function ConsentSheet({ purposes }: { purposes: string[] }) {
  const { t, tx } = useI18n();
  const { c } = useTheme();
  const qc = useQueryClient();
  const [checked, setChecked] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState(false);

  const agree = async () => {
    setBusy(true);
    try {
      for (const purpose of purposes) await api('/auth/consents', { method: 'POST', body: { purpose, granted: true } });
      haptics.success();
      await qc.invalidateQueries({ queryKey: ['session'] });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Sheet open onClose={() => undefined} title={t('consent.title')}>
      <T tone="muted" style={{ marginBottom: space.lg }}>{t('consent.body')}</T>
      {purposes.map((p) => {
        const on = !!checked[p];
        return (
          <Pressable
            key={p}
            accessibilityRole="checkbox"
            accessibilityState={{ checked: on }}
            onPress={() => { haptics.select(); setChecked((v) => ({ ...v, [p]: !on })); }}
            style={[styles.option, { borderColor: on ? c.brand : c.line, backgroundColor: on ? c.brandSoft : c.surface }]}
          >
            <View style={[styles.box, { borderColor: on ? c.brand : c.muted, backgroundColor: on ? c.brand : 'transparent' }]}>{on ? <Check size={16} color="#fff" /> : null}</View>
            <T style={{ flex: 1 }}>{tx('consent.' + p, p)}</T>
          </Pressable>
        );
      })}
      <Button title={t('consent.continue')} onPress={() => void agree()} loading={busy} disabled={!purposes.every((p) => checked[p])} style={{ marginTop: space.md }} />
    </Sheet>
  );
}

const styles = StyleSheet.create({
  option: { flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.lg, borderRadius: radius.md, borderWidth: 1.5, marginBottom: space.md },
  box: { width: 24, height: 24, borderRadius: 7, borderWidth: 2, alignItems: 'center', justifyContent: 'center' },
});
