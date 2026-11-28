/** WebGL check, once per page load. Phones without it get the 2D step-by-step view. */
let webgl: boolean | undefined;
export function canWebGL(): boolean {
  if (webgl === undefined) {
    try {
      const c = document.createElement('canvas');
      webgl = !!(c.getContext('webgl2') ?? c.getContext('webgl'));
    } catch {
      webgl = false;
    }
  }
  return webgl;
}
