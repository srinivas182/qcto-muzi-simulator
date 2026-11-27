/**
 * Start-up marker for the cold-start measurement in CI (roadmap commitment C4).
 * The first screen to appear writes one line to the Android log in release builds only; CI times the gap
 * between launching the app and this line ("time to first screen"), on top of Android's own "Displayed" time.
 */
let marked = false;

export const READY_MARKER = '[SimuWork] first screen';

export function markFirstScreen(): void {
  if (marked) return;
  marked = true;
  if (!__DEV__) console.info(READY_MARKER);
}
