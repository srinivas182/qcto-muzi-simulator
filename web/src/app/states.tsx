import { Link, useRouteError } from 'react-router';
import { Compass, Lock, Sparkles, TriangleAlert } from 'lucide-react';
import { EmptyState, Button, Skeleton, Card } from '../components/ui';
import { useI18n } from '../lib/i18n';

/** First-paint placeholder shaped like the app shell (no spinner, no layout jump). */
export function ShellSkeleton() {
  return (
    <div className="flex min-h-dvh" aria-busy="true" aria-label="Loading">
      <div className="hidden w-64 shrink-0 bg-nav lg:block" />
      <div className="flex-1">
        <div className="h-16 border-b border-line" />
        <div className="mx-auto max-w-[1240px] space-y-4 px-4 py-8 sm:px-6 lg:px-10">
          <Skeleton className="h-8 w-64" />
          <Skeleton className="h-4 w-96 max-w-full" />
          <div className="grid gap-4 pt-4 sm:grid-cols-3">
            <Skeleton className="h-28" />
            <Skeleton className="h-28" />
            <Skeleton className="h-28" />
          </div>
          <Skeleton className="h-64" />
        </div>
      </div>
    </div>
  );
}

export function NoAccess() {
  const { t } = useI18n();
  return (
    <Card>
      <EmptyState icon={<Lock className="size-6" />} title={t('common.noAccess.title')} body={t('common.noAccess.body')} action={<Link to="/"><Button variant="secondary">{t('common.goHome')}</Button></Link>} />
    </Card>
  );
}

export function NotFound() {
  const { t } = useI18n();
  return (
    <Card>
      <EmptyState icon={<Compass className="size-6" />} title={t('common.notFound.title')} body={t('common.notFound.body')} action={<Link to="/"><Button variant="secondary">{t('common.goHome')}</Button></Link>} />
    </Card>
  );
}

export function ComingSoon({ title }: { title: string }) {
  const { t } = useI18n();
  return (
    <>
      <h1 className="mb-6 text-[26px] font-bold tracking-tight text-ink">{title}</h1>
      <Card>
        <EmptyState icon={<Sparkles className="size-6" />} title={t('common.comingSoon.title')} body={t('common.comingSoon.body')} />
      </Card>
    </>
  );
}

export function RouteError() {
  const error = useRouteError();
  const { t } = useI18n();
  console.error(error);
  return (
    <div className="flex flex-1 items-center justify-center p-6">
      <EmptyState icon={<TriangleAlert className="size-6" />} title={t('common.error')} body={t('common.networkError')} action={<Button onClick={() => location.reload()}>{t('common.retry')}</Button>} />
    </div>
  );
}
