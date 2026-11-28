/**
 * The web app loads each non-English language in two parts, so a learner on a cheap phone downloads only what
 * learner screens use. Keys whose first segment is listed here are in the "learner" part (shared shell,
 * sign-in, learner home, joining a class, the learner's own QCTO details); HQ's content studios are "hq"
 * (only HQ staff download them); everything else is "staff".
 * English ships whole with the app. The source stays one file per language (translators and the mobile app
 * see no change); the split happens when the web app is built.
 */
export const LEARNER_PREFIXES = ['common', 'nav', 'auth', 'm', 'home', 'lh', 'join', 'consent', 'shell', 'palette', 'section', 'hold', 'qd', 'lv', 'lp', 'sim', 'jr', 'off', 'as'] as const;

/** Course Studio, Lesson Studio, Scenario Studio and the assessment plan: used only by HQ. */
export const HQ_PREFIXES = ['cs', 'ls', 'ss', 'ap'] as const;

export type LocalePart = 'learner' | 'staff' | 'hq';

export const partOf = (key: string): LocalePart => {
  const head = key.split('.')[0] ?? '';
  if ((LEARNER_PREFIXES as readonly string[]).includes(head)) return 'learner';
  return (HQ_PREFIXES as readonly string[]).includes(head) ? 'hq' : 'staff';
};
