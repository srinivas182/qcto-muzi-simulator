import { useNavigate } from 'react-router';
import { PauseCircle } from 'lucide-react';
import { Button } from '../components/ui';
import { useI18n } from '../lib/i18n';
import { useSignOut } from '../lib/session';

/** Shown instead of the app to staff of a suspended provider. Learners never see it. (The footer comes from RootLayout.) */
export function OnHold({ academy }: { academy: string }) {
  const { t } = useI18n();
  const signOut = useSignOut();
  const navigate = useNavigate();
  return (
    <div className="flex flex-1 flex-col">
      <main className="mx-auto flex w-full max-w-md flex-1 flex-col items-center justify-center px-6 text-center">
        <span className="mb-5 flex size-14 items-center justify-center rounded-2xl bg-[#FDF4E1] text-[#8A5A00]"><PauseCircle className="size-7" /></span>
        <h1 className="text-[24px] font-bold tracking-tight text-ink">{t('hold.title')}</h1>
        <p className="mt-2 text-[15px] text-muted">{t('hold.body', { academy })}</p>
        <Button className="mt-6" variant="secondary" onClick={() => signOut.mutate(undefined, { onSettled: () => navigate('/sign-in', { replace: true }) })}>{t('common.signOut')}</Button>
      </main>
    </div>
  );
}
