import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { ProgrammeStudioData, ProgrammeVersionContent } from '@simuwork/core';
import { api } from '../../lib/api';

export const programmeKey = (code: string) => ['hq-programme', code] as const;

/** Runs a Studio call and puts the returned draft straight into the page cache (no refetch flicker). */
export function useStudioMutation(code: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ method, path, body }: { method: 'POST' | 'PATCH' | 'DELETE'; path: string; body?: unknown }) =>
      api<{ data?: ProgrammeVersionContent }>(`/platform/programmes/${code}${path}`, { method, body }),
    onSuccess: (res, vars) => {
      const changesStatus = /\/(submit|approve|request-changes|publish)$/.test(vars.path) || (vars.path === '/draft' && vars.method !== 'PATCH');
      if (res.data && !changesStatus) {
        qc.setQueryData<ProgrammeStudioData>(programmeKey(code), (old) => (old ? { ...old, current: res.data! } : old));
      } else {
        void qc.invalidateQueries({ queryKey: programmeKey(code) });
      }
      void qc.invalidateQueries({ queryKey: ['hq-programmes'] });
    },
  });
}
