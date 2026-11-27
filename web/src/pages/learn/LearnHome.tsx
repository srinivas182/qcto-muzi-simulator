import { Box, PlayCircle, Sparkles, Wifi } from 'lucide-react';
import { useSignedIn } from '../../app/access';
import { Card } from '../../components/ui';
import { useI18n } from '../../lib/i18n';
import { greetingKey } from '../../lib/time';
import { useState } from 'react';

/** Learner home. Programmes, simulators and courses fill this in Sprint 2+. */
export default function LearnHome() {
  const session = useSignedIn();
  const { t } = useI18n();
  const [hour] = useState(() => new Date().getHours());
  const first = session.user.name.split(' ')[0] ?? session.user.name;

  return (
    <div className="sw-in mx-auto max-w-3xl">
      <p className="text-[14px] font-semibold text-muted">{t('home.learnLead')}</p>
      <h1 className="mt-1 text-[30px] font-bold tracking-tight text-ink">{t(greetingKey(hour), { name: first })}</h1>

      <Card className="relative mt-6 overflow-hidden p-0">
        <div className="relative h-44 overflow-hidden bg-nav">
          <div aria-hidden className="absolute -left-10 top-6 size-56 rounded-full bg-brand opacity-60 blur-[70px]" />
          <div aria-hidden className="absolute -right-6 -top-10 size-48 rounded-full bg-accent opacity-40 blur-[70px]" />
          <div aria-hidden className="absolute inset-0 flex items-center justify-center gap-5">
            {[Box, PlayCircle, Sparkles].map((Icon, i) => (
              <span key={i} className="flex size-16 items-center justify-center rounded-2xl border border-white/15 bg-white/10 text-white backdrop-blur-md" style={{ transform: `translateY(${i === 1 ? -10 : 6}px) rotate(${(i - 1) * 6}deg)` }}>
                <Icon className="size-7" />
              </span>
            ))}
          </div>
        </div>
        <div className="p-6">
          <p className="text-[15px] text-ink">{t('home.learnEmpty')}</p>
          <p className="mt-3 flex items-center gap-2 text-[13px] text-muted"><Wifi className="size-4" /> {t('home.learnTip')}</p>
        </div>
      </Card>
    </div>
  );
}
