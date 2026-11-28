import { useState } from 'react';
import { Box, CheckCircle2, Lightbulb, Volume2, XCircle } from 'lucide-react';
import type { ContentAssetItem, LessonBlock } from '@simuwork/core';
import { Markdown } from '../Markdown';
import { cx } from '../ui';
import { useI18n } from '../../lib/i18n';

/**
 * Renders a lesson's blocks. Used by Course Studio's live preview and by the learner's course player.
 * Built for small screens and little data: on data saver, video becomes its text and narration stays text.
 */
export function LessonView({ blocks, assets, scenarios, dataSaver = false, onAnswer, onTry }: {
  blocks: LessonBlock[];
  assets: Record<string, ContentAssetItem>;
  scenarios?: Record<string, string>;
  dataSaver?: boolean;
  onAnswer?: (blockId: string, correct: boolean) => void;
  onTry?: (code: string) => void;
}) {
  return <div className="space-y-4">{blocks.map((b) => <Block key={b.id} b={b} assets={assets} scenarios={scenarios ?? {}} dataSaver={dataSaver} onAnswer={onAnswer} onTry={onTry} />)}</div>;
}

function Block({ b, assets, scenarios, dataSaver, onAnswer, onTry }: {
  b: LessonBlock; assets: Record<string, ContentAssetItem>; scenarios: Record<string, string>; dataSaver: boolean;
  onAnswer?: (blockId: string, correct: boolean) => void; onTry?: (code: string) => void;
}) {
  const { t } = useI18n();
  switch (b.type) {
    case 'text':
      return <div className="text-[15px] leading-relaxed text-ink"><Markdown source={b.body} /></div>;
    case 'keypoints':
      return (
        <ul className="space-y-2">
          {b.items.map((item, i) => (
            <li key={i} className="flex gap-3 rounded-xl bg-surface-2 px-4 py-3 text-[15px] text-ink"><Lightbulb className="mt-0.5 size-4 shrink-0 text-brand" />{item}</li>
          ))}
        </ul>
      );
    case 'image': {
      const a = assets[b.asset_id];
      return (
        <figure>
          {a ? <img src={a.url} alt={b.alt} loading="lazy" className="w-full rounded-xl" /> : <div className="flex h-36 items-center justify-center rounded-xl bg-surface-2 text-[13px] text-muted">{t('lv.noPicture')}</div>}
          <figcaption className="mt-1.5 text-[13px] text-muted">{b.alt}</figcaption>
        </figure>
      );
    }
    case 'narration': {
      const audio = b.audio_asset_id ? assets[b.audio_asset_id] : undefined;
      return (
        <div className="rounded-2xl border border-brand/20 bg-brand/5 p-4">
          <p className="flex items-start gap-3 text-[15px] leading-relaxed text-ink"><Volume2 className="mt-0.5 size-5 shrink-0 text-brand" />{b.script}</p>
          {audio && !dataSaver && <audio controls preload="none" src={audio.url} className="mt-3 w-full" aria-label={t('lv.listen')} />}
        </div>
      );
    }
    case 'video': {
      const v = assets[(dataSaver ? b.low_asset_id : null) ?? b.asset_id];
      return dataSaver || !v
        ? <div className="rounded-xl bg-surface-2 p-4 text-[15px] text-ink"><Markdown source={b.text} /></div>
        : <video controls preload="none" src={v.url} className="w-full rounded-xl bg-black" aria-label={b.text.slice(0, 80)} />;
    }
    case 'check':
      return <Check b={b} onAnswer={onAnswer} />;
    case 'try':
      return (
        <button type="button" onClick={() => onTry?.(b.activity_code)} className="flex w-full items-center gap-4 rounded-2xl bg-nav p-4 text-left text-white transition hover:brightness-110">
          <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white/10"><Box className="size-6" /></span>
          <span><span className="block text-[12px] font-semibold uppercase tracking-wide text-white/70">{t('lv.tryIt')}</span><span className="block text-[16px] font-bold">{scenarios[b.activity_code] ?? b.activity_code}</span></span>
        </button>
      );
  }
}

function Check({ b, onAnswer }: { b: Extract<LessonBlock, { type: 'check' }>; onAnswer?: (blockId: string, correct: boolean) => void }) {
  const { t } = useI18n();
  // "Fix it": a wrong first answer gets a hint and one more try before the answer is shown.
  // Only the first answer counts towards the score.
  const [picks, setPicks] = useState<number[]>([]);
  const picked = picks[picks.length - 1] ?? null;
  const done = picked === b.answer || picks.length >= 2;
  const right = picked === b.answer;
  const retrying = picks.length === 1 && !done;
  return (
    <fieldset className="rounded-2xl border border-line p-4">
      <legend className="px-1 text-[12px] font-semibold uppercase tracking-wide text-muted">{t('lv.quickCheck')}</legend>
      <p className="text-[16px] font-semibold text-ink">{b.prompt}</p>
      <div className="mt-3 space-y-2">
        {b.options.map((o, i) => (
          <button key={i} type="button" disabled={done || picks.includes(i)} aria-pressed={picked === i}
            onClick={() => { if (picks.length === 0) onAnswer?.(b.id, i === b.answer); setPicks((p) => [...p, i]); }}
            className={cx('flex min-h-12 w-full items-center gap-3 rounded-xl border px-4 py-2.5 text-left text-[15px] transition',
              !done && !picks.includes(i) && 'border-line hover:border-brand hover:bg-surface-2',
              retrying && picks.includes(i) && 'border-[#C92A2F] bg-[#FDECEC] text-[#8A1C20] line-through opacity-70 dark:bg-[#B42328]/20 dark:text-[#FF9A9E]',
              done && i === b.answer && 'border-[#1E7A46] bg-[#E6F4EC] text-[#14532D] dark:bg-[#1E7A46]/20 dark:text-[#6FD49A]',
              done && picked === i && i !== b.answer && 'border-[#C92A2F] bg-[#FDECEC] text-[#8A1C20] dark:bg-[#B42328]/20 dark:text-[#FF9A9E]',
              done && picked !== i && i !== b.answer && 'border-line opacity-60')}>
            {done && i === b.answer ? <CheckCircle2 className="size-5 shrink-0" /> : picks.includes(i) ? <XCircle className="size-5 shrink-0" /> : <span className="size-5 shrink-0 rounded-full border-2 border-line" />}
            {o}
          </button>
        ))}
      </div>
      {retrying && <p role="status" className="mt-3 text-[14px] text-ink"><span className="font-semibold">{t('lv.notQuite')}</span> {b.hint || t('lv.tryOnce')}</p>}
      {done && <p role="status" className="mt-3 text-[14px] text-ink"><span className="font-semibold">{t(right ? (picks.length > 1 ? 'lv.fixedIt' : 'lv.right') : 'lv.notQuite')}</span> {b.explain}</p>}
    </fieldset>
  );
}
