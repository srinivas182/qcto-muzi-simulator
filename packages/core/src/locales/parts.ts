/**
 * The web app loads each non-English language in two parts, so a learner on a cheap phone downloads only what
 * learner screens use. Keys whose first segment is listed here are in the "learner" part (shared shell,
 * sign-in, learner home, joining a class, the learner's own QCTO details); everything else is "staff".
 * English ships whole with the app. The source stays one file per language (translators and the mobile app
 * see no change); the split happens when the web app is built.
 */
export const LEARNER_PREFIXES = ['common', 'nav', 'auth', 'm', 'home', 'lh', 'join', 'consent', 'shell', 'palette', 'section', 'hold', 'qd'] as const;

export type LocalePart = 'learner' | 'staff';

export const partOf = (key: string): LocalePart => ((LEARNER_PREFIXES as readonly string[]).includes(key.split('.')[0] ?? '') ? 'learner' : 'staff');
