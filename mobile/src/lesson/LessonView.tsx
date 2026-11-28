import { useState } from 'react';
import { Image, Pressable, StyleSheet, View } from 'react-native';
import { useAudioPlayer, useAudioPlayerStatus } from 'expo-audio';
import type { ContentAssetItem, LessonBlock } from '@simuwork/core';
import { useI18n } from '../lib/i18n';
import { Box, Check, PlayCircle, Sparkles, X } from '../ui/icons';
import { haptics, radius, space, T, useTheme } from '../ui';

/**
 * A lesson's blocks on the phone. Data saver (on by default) keeps narration as text, shows a picture only
 * when tapped, and replaces video with its text, so a lesson costs a few kilobytes.
 */
export function LessonView({ blocks, assets, scenarios, dataSaver, onAnswer, onTry }: {
  blocks: LessonBlock[]; assets: Record<string, ContentAssetItem>; scenarios: Record<string, string>; dataSaver: boolean;
  onAnswer: (blockId: string, correct: boolean) => void; onTry: (code: string) => void;
}) {
  return <View style={{ gap: space.lg }}>{blocks.map((b) => <Block key={b.id} b={b} assets={assets} scenarios={scenarios} dataSaver={dataSaver} onAnswer={onAnswer} onTry={onTry} />)}</View>;
}

/** Light Markdown: **bold** only, and line breaks. */
function Rich({ text }: { text: string }) {
  return <T style={{ lineHeight: 24 }}>{text.split(/(\*\*[^*]+\*\*)/g).map((part, i) => (part.startsWith('**') ? <T key={i} style={{ fontWeight: '700' }}>{part.slice(2, -2)}</T> : part))}</T>;
}

function Block({ b, assets, scenarios, dataSaver, onAnswer, onTry }: {
  b: LessonBlock; assets: Record<string, ContentAssetItem>; scenarios: Record<string, string>; dataSaver: boolean;
  onAnswer: (blockId: string, correct: boolean) => void; onTry: (code: string) => void;
}) {
  const { t } = useI18n();
  const { c } = useTheme();
  const [showPicture, setShowPicture] = useState(false);
  switch (b.type) {
    case 'text':
      return <Rich text={b.body} />;
    case 'keypoints':
      return <View style={{ gap: space.sm }}>{b.items.map((it, i) => <View key={i} style={[styles.point, { backgroundColor: c.surface, borderColor: c.line }]}><Sparkles size={16} color={c.brand} /><T style={{ flex: 1 }}>{it}</T></View>)}</View>;
    case 'narration': {
      const audio = b.audio_asset_id ? assets[b.audio_asset_id] : undefined;
      return (
        <View style={[styles.narration, { backgroundColor: c.brandSoft }]}>
          <T style={{ lineHeight: 24 }}>{b.script}</T>
          {audio && !dataSaver ? <Listen url={audio.url} /> : null}
        </View>
      );
    }
    case 'image': {
      const a = assets[b.asset_id];
      return (
        <View>
          {a && (!dataSaver || showPicture)
            ? <Image source={{ uri: a.url }} style={styles.image} accessibilityLabel={b.alt} resizeMode="cover" />
            : <Pressable accessibilityRole="button" onPress={() => setShowPicture(true)} style={[styles.imagePlaceholder, { backgroundColor: c.surface, borderColor: c.line }]}><T tone="brand">{t('lv.showPicture')}</T></Pressable>}
          <T variant="caption" tone="muted" style={{ marginTop: 6 }}>{b.alt}</T>
        </View>
      );
    }
    case 'video':
      return <View style={[styles.point, { backgroundColor: c.surface, borderColor: c.line }]}><Rich text={b.text} /></View>;
    case 'check':
      return <CheckBlock b={b} onAnswer={onAnswer} />;
    case 'try':
      return (
        <Pressable accessibilityRole="button" onPress={() => onTry(b.activity_code)} style={[styles.try, { backgroundColor: c.nav }]}>
          <Box size={26} color="#FFFFFF" />
          <View style={{ flex: 1 }}><T variant="caption" tone="inverse" style={{ opacity: 0.75 }}>{t('lv.tryIt')}</T><T tone="inverse" style={{ fontWeight: '700' }}>{scenarios[b.activity_code] ?? b.activity_code}</T></View>
        </Pressable>
      );
  }
}

function Listen({ url }: { url: string }) {
  const { t } = useI18n();
  const { c } = useTheme();
  const player = useAudioPlayer(url);
  const status = useAudioPlayerStatus(player);
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={t('lv.listen')} onPress={() => (status.playing ? player.pause() : player.play())} style={[styles.listen, { borderColor: c.brand }]}>
      <PlayCircle size={20} color={c.brand} /><T tone="brand" style={{ fontWeight: '700' }}>{t('lv.listen')}</T>
    </Pressable>
  );
}

function CheckBlock({ b, onAnswer }: { b: Extract<LessonBlock, { type: 'check' }>; onAnswer: (blockId: string, correct: boolean) => void }) {
  const { t } = useI18n();
  const { c } = useTheme();
  // "Fix it": a wrong first answer gets a hint and one more try; only the first answer counts.
  const [picks, setPicks] = useState<number[]>([]);
  const picked = picks[picks.length - 1] ?? null;
  const done = picked === b.answer || picks.length >= 2;
  const retrying = picks.length === 1 && !done;
  return (
    <View style={[styles.check, { borderColor: c.line }]}>
      <T variant="overline" tone="muted">{t('lv.quickCheck')}</T>
      <T style={{ fontWeight: '700', marginTop: 4, fontSize: 17 }}>{b.prompt}</T>
      <View style={{ gap: space.sm, marginTop: space.md }}>
        {b.options.map((o, i) => {
          const right = done && i === b.answer;
          const wrong = picks.includes(i) && i !== b.answer;
          return (
            <Pressable key={i} accessibilityRole="button" accessibilityState={{ disabled: done || wrong, selected: picked === i }} disabled={done || wrong}
              onPress={() => { if (picks.length === 0) onAnswer(b.id, i === b.answer); setPicks((p) => [...p, i]); (i === b.answer ? haptics.success : haptics.warn)(); }}
              style={[styles.option, { borderColor: right ? c.success : wrong ? c.danger : c.line, backgroundColor: c.surface, opacity: done && !right && !wrong ? 0.6 : 1 }]}>
              {right ? <Check size={18} color={c.success} /> : wrong ? <X size={18} color={c.danger} /> : <View style={[styles.dot, { borderColor: c.line }]} />}
              <T style={{ flex: 1 }}>{o}</T>
            </Pressable>
          );
        })}
      </View>
      {retrying && <T accessibilityLiveRegion="polite" style={{ marginTop: space.md }}><T style={{ fontWeight: '700' }}>{`${t('lv.notQuite')} `}</T>{b.hint || t('lv.tryOnce')}</T>}
      {done && <T accessibilityLiveRegion="polite" style={{ marginTop: space.md }}><T style={{ fontWeight: '700' }}>{`${t(picked === b.answer ? (picks.length > 1 ? 'lv.fixedIt' : 'lv.right') : 'lv.notQuite')} `}</T>{b.explain}</T>}
    </View>
  );
}

const styles = StyleSheet.create({
  point: { flexDirection: 'row', gap: space.sm, alignItems: 'flex-start', padding: space.md, borderRadius: radius.md, borderWidth: 1 },
  narration: { padding: space.lg, borderRadius: radius.lg, gap: space.md },
  listen: { flexDirection: 'row', alignItems: 'center', gap: 8, alignSelf: 'flex-start', paddingHorizontal: space.md, minHeight: 44, borderRadius: radius.md, borderWidth: 1.5 },
  image: { width: '100%', aspectRatio: 4 / 3, borderRadius: radius.md },
  imagePlaceholder: { height: 120, borderRadius: radius.md, borderWidth: 1, borderStyle: 'dashed', alignItems: 'center', justifyContent: 'center' },
  check: { padding: space.lg, borderRadius: radius.lg, borderWidth: 1 },
  option: { flexDirection: 'row', alignItems: 'center', gap: space.sm, minHeight: 52, paddingHorizontal: space.md, borderRadius: radius.md, borderWidth: 1.5 },
  dot: { width: 18, height: 18, borderRadius: 9, borderWidth: 2 },
  try: { flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.lg, borderRadius: radius.lg, minHeight: 72 },
});
