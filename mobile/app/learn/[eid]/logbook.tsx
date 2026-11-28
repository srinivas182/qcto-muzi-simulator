import { useCallback, useEffect, useRef, useState } from 'react';
import { Pressable, StyleSheet, TextInput, View } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo from '@react-native-community/netinfo';
import { useLocalSearchParams } from 'expo-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import type { CheckIn, LogbookEntryOut, LogbookView } from '@simuwork/core';
import { api, ApiError } from '../../../src/lib/api';
import { useI18n } from '../../../src/lib/i18n';
import { compressPhoto } from '../../../src/lib/photo';
import { Camera, Check, WifiOff } from '../../../src/ui/icons';
import { Button, Card, radius, Screen, space, T, useTheme } from '../../../src/ui';

type Waiting = CheckIn & { photo?: string };
const KEY = (eid: string) => `sw.logbook.${eid}`;
const load = async (eid: string): Promise<Waiting[]> => { try { return JSON.parse((await AsyncStorage.getItem(KEY(eid))) ?? '[]') as Waiting[]; } catch { return []; } };
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const uuid = () => 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (ch) => { const r = (Math.random() * 16) | 0; return (ch === 'x' ? r : (r & 0x3) | 0x8).toString(16); });

async function upload(eid: string, w: Waiting) {
  const { photo, ...body } = w;
  const r = await api<{ data: LogbookEntryOut }>(`/learn/enrolments/${eid}/logbook`, { method: 'POST', body });
  if (photo) {
    const form = new FormData();
    form.append('photo', { uri: photo, name: 'day.jpg', type: 'image/jpeg' } as unknown as Blob);
    await api(`/learn/logbook/${r.data.id}/photo`, { method: 'POST', body: form }).catch(() => undefined);
  }
}

/** Learner: daily workplace check-ins, kept on the phone when there is no signal (Sprint 5.3). */
export default function LogbookScreen() {
  const { eid } = useLocalSearchParams<{ eid: string }>();
  const { t, locale } = useI18n();
  const { c } = useTheme();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['logbook', eid], queryFn: () => api<{ data: LogbookView }>(`/learn/enrolments/${eid}/logbook`) });
  const [queued, setQueued] = useState(0);
  const flush = useCallback(async () => {
    let sent = 0;
    for (const w of await load(eid)) {
      try { await upload(eid, w); sent++; } catch (e) { if (!(e instanceof ApiError) || e.status === 0) break; }
      await AsyncStorage.setItem(KEY(eid), JSON.stringify((await load(eid)).filter((x) => x.client_id !== w.client_id)));
    }
    setQueued((await load(eid)).length);
    if (sent) void qc.invalidateQueries({ queryKey: ['logbook', eid] });
  }, [eid, qc]);
  useEffect(() => {
    void flush();
    return NetInfo.addEventListener((s) => { if (s.isConnected) void flush(); });
  }, [flush]);
  const v = q.data?.data;
  const date = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' });
  return (
    <Screen title={t('as.lb.title')} subtitle={t('as.lb.lead')} refreshing={q.isRefetching} onRefresh={() => void q.refetch()}>
      {!v ? null : !v.workplace ? <T tone="muted">{t('as.lb.noPlacement')}</T> : (
        <>
          <Card style={{ marginBottom: space.md, flexDirection: 'row', alignItems: 'center', gap: space.md }}>
            <View style={{ flex: 1 }}>
              <T style={{ fontWeight: '700' }}>{v.workplace.employer}{v.workplace.site ? ` · ${v.workplace.site}` : ''}</T>
              <T variant="caption" tone="muted">{t('as.lb.mentor', { name: v.workplace.mentor_name })}</T>
            </View>
            <View style={{ alignItems: 'flex-end' }}>
              <T style={{ fontWeight: '800', fontSize: 20 }}>{`${v.summary.confirmed_days}${v.summary.min_days ? `/${v.summary.min_days}` : ''}`}</T>
              <T variant="caption" tone="muted">{t('as.lb.daysConfirmed')}</T>
            </View>
          </Card>
          <CheckInForm eid={eid} v={v} onDone={async (offline) => { setQueued((await load(eid)).length); if (!offline) void qc.invalidateQueries({ queryKey: ['logbook', eid] }); }} />
          {queued > 0 ? <View style={[styles.row, styles.note]}><WifiOff size={16} color="#7A4B00" /><T style={{ flex: 1, color: '#7A4B00' }}>{t('as.lb.waiting', { n: queued })}</T></View> : null}
          <Card style={{ padding: 0 }}>
            {v.entries.length === 0 ? <T tone="muted" style={{ padding: space.lg }}>{t('as.lb.none')}</T> : v.entries.map((x, i) => (
              <View key={x.id} style={[styles.entry, i > 0 && { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: c.line }]}>
                <View style={{ flex: 1 }}>
                  <T style={{ fontWeight: '700' }}>{`${date(x.day)} · ${t('as.lb.hours', { n: x.hours })}`}</T>
                  {x.note ? <T variant="caption" tone="muted">{x.note}</T> : null}
                  {x.status === 'queried' && x.mentor_note ? <T variant="caption" style={{ color: '#8A5A00', fontWeight: '600' }}>{t('as.lb.queried', { note: x.mentor_note })}</T> : null}
                </View>
                <T variant="caption" tone={x.status === 'confirmed' ? 'success' : 'muted'} style={{ fontWeight: '700' }}>
                  {t(x.status === 'confirmed' ? 'as.lb.confirmed' : x.status === 'queried' ? 'as.lb.query' : 'as.lb.pending')}
                </T>
              </View>
            ))}
          </Card>
        </>
      )}
    </Screen>
  );
}

function CheckInForm({ eid, v, onDone }: { eid: string; v: LogbookView; onDone: (offline: boolean) => void }) {
  const { t, locale } = useI18n();
  const { c } = useTheme();
  const days = Array.from({ length: 7 }, (_, i) => iso(new Date(Date.now() - i * 86_400_000)));
  const [day, setDay] = useState(days[0]!);
  const [hours, setHours] = useState(8);
  const [outcomes, setOutcomes] = useState<string[]>([]);
  const [note, setNote] = useState('');
  const [photo, setPhoto] = useState<string | null>(null);
  const [shooting, setShooting] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [perm, ask] = useCameraPermissions();
  const cam = useRef<CameraView>(null);

  const send = async () => {
    setBusy(true); setError(null);
    const w: Waiting = { client_id: uuid(), day, hours, outcomes, note: note.trim() || null, photo: photo ?? undefined };
    try {
      await upload(eid, w);
      setOutcomes([]); setNote(''); setPhoto(null); onDone(false);
    } catch (e) {
      if (e instanceof ApiError && e.status !== 0) setError(Object.values(e.errors)[0]?.[0] ?? e.message);
      else { await AsyncStorage.setItem(KEY(eid), JSON.stringify([...(await load(eid)), w])); setOutcomes([]); setNote(''); setPhoto(null); onDone(true); }
    } finally { setBusy(false); }
  };

  return (
    <Card style={{ marginBottom: space.md, gap: space.md }}>
      <T style={{ fontWeight: '700', fontSize: 16 }}>{t('as.lb.checkIn')}</T>
      <View style={styles.wrap} accessibilityRole="radiogroup" accessibilityLabel={t('as.lb.day')}>
        {days.map((d) => (
          <Pressable key={d} accessibilityRole="radio" accessibilityState={{ checked: day === d }} onPress={() => setDay(d)}
            style={[styles.chip, { borderColor: day === d ? c.brand : c.line, backgroundColor: day === d ? c.brandSoft : 'transparent' }]}>
            <T variant="caption" style={{ fontWeight: '700' }}>{new Date(`${d}T12:00:00`).toLocaleDateString(locale, { weekday: 'short', day: 'numeric' })}</T>
          </Pressable>
        ))}
      </View>
      <View style={[styles.row, { justifyContent: 'space-between' }]}>
        <T style={{ fontWeight: '600' }}>{t('as.lb.hoursLabel')}</T>
        <View style={styles.row}>
          <Pressable accessibilityRole="button" accessibilityLabel="−" onPress={() => setHours(Math.max(0.5, hours - 0.5))} style={[styles.step, { borderColor: c.line }]}><T style={{ fontWeight: '800' }}>−</T></Pressable>
          <T style={{ fontWeight: '800', minWidth: 40, textAlign: 'center' }} accessibilityLabel={t('as.lb.hoursLabel')}>{String(hours)}</T>
          <Pressable accessibilityRole="button" accessibilityLabel="+" onPress={() => setHours(Math.min(12, hours + 0.5))} style={[styles.step, { borderColor: c.line }]}><T style={{ fontWeight: '800' }}>+</T></Pressable>
        </View>
      </View>
      <T style={{ fontWeight: '600' }}>{t('as.lb.what')}</T>
      {v.outcomes.map((o) => {
        const on = outcomes.includes(o.code);
        return (
          <Pressable key={o.code} accessibilityRole="checkbox" accessibilityState={{ checked: on }} accessibilityLabel={o.statement}
            onPress={() => setOutcomes(on ? outcomes.filter((x) => x !== o.code) : [...outcomes, o.code])}
            style={[styles.row, styles.option, { borderColor: on ? c.brand : c.line, backgroundColor: on ? c.brandSoft : 'transparent' }]}>
            <View style={[styles.box, on && { backgroundColor: c.brand, borderColor: c.brand }]}>{on ? <Check size={14} color="#fff" /> : null}</View>
            <T style={{ flex: 1 }}>{o.statement}</T>
          </Pressable>
        );
      })}
      <TextInput accessibilityLabel={t('as.lb.note')} placeholder={t('as.lb.notePlaceholder')} placeholderTextColor={c.muted} multiline maxLength={500} value={note} onChangeText={setNote}
        style={[styles.input, { borderColor: c.line, color: c.text }]} />
      {shooting && perm?.granted ? (
        <View style={{ gap: space.sm }}>
          <CameraView ref={cam} facing="back" style={styles.camera} />
          <Button title={t('as.lb.photo')} onPress={async () => {
            try {
              const shot = await cam.current?.takePictureAsync({ quality: 0.7 });
              if (shot) setPhoto(await compressPhoto(shot.uri));
            } catch { setError(t('as.lb.photoFail')); }
            setShooting(false);
          }} />
        </View>
      ) : (
        <Pressable accessibilityRole="button" onPress={async () => { if (!perm?.granted) await ask(); setShooting(true); }} style={[styles.row, styles.option, { borderColor: c.line }]}>
          <Camera size={18} color={c.brand} /><T tone="brand" style={{ fontWeight: '700' }}>{t(photo ? 'as.lb.photoChange' : 'as.lb.photo')}</T>
        </Pressable>
      )}
      {error ? <T tone="danger">{error}</T> : null}
      <Button title={t('as.lb.send')} loading={busy} disabled={outcomes.length === 0} onPress={() => void send()} />
    </Card>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  wrap: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  chip: { borderWidth: 1.5, borderRadius: radius.md, paddingHorizontal: 10, paddingVertical: 8 },
  step: { width: 40, height: 40, borderRadius: radius.md, borderWidth: 1, alignItems: 'center', justifyContent: 'center' },
  option: { borderWidth: 1.5, borderRadius: radius.md, padding: space.md, minHeight: 48 },
  box: { width: 22, height: 22, borderRadius: 6, borderWidth: 2, borderColor: '#5B6577', alignItems: 'center', justifyContent: 'center' },
  input: { borderWidth: 1, borderRadius: radius.md, minHeight: 72, padding: space.md, textAlignVertical: 'top' },
  camera: { width: '100%', aspectRatio: 4 / 3, borderRadius: radius.md, overflow: 'hidden' },
  entry: { flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.lg },
  note: { backgroundColor: '#FFF4E5', borderRadius: radius.md, padding: space.md, marginBottom: space.md },
});
