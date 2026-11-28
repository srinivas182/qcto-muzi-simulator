/**
 * Make a phone photo small enough for the logbook (Sprint 5.3): at most 1024 px on the long side, JPEG, and
 * under the byte limit (150 KB), lowering the quality until it fits. Runs in the browser; nothing is uploaded
 * until it is small.
 */
export async function compressPhoto(file: Blob, maxBytes = 150 * 1024, maxSide = 1024): Promise<Blob> {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close?.();
  for (const q of [0.75, 0.6, 0.45, 0.3]) {
    const blob = await new Promise<Blob | null>((ok) => canvas.toBlob(ok, 'image/jpeg', q));
    if (blob && blob.size <= maxBytes) return blob;
  }
  throw new Error('photo-too-big');
}

export const blobToDataUrl = (b: Blob) => new Promise<string>((ok, no) => { const r = new FileReader(); r.onload = () => ok(String(r.result)); r.onerror = no; r.readAsDataURL(b); });
export const dataUrlToBlob = async (u: string) => (await fetch(u)).blob();
