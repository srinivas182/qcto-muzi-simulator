import { File } from 'expo-file-system';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';

/**
 * Make a phone photo small enough for the logbook (Sprint 5.3): resize on the phone and lower the JPEG quality
 * until it is 150 KB or less, before anything is sent. Data costs the learner money.
 */
export async function compressPhoto(uri: string, maxBytes = 150 * 1024): Promise<string> {
  for (const width of [1024, 800, 640]) {
    const image = await ImageManipulator.manipulate(uri).resize({ width }).renderAsync();
    for (const compress of [0.6, 0.45, 0.3]) {
      const out = await image.saveAsync({ compress, format: SaveFormat.JPEG });
      if ((new File(out.uri).size ?? Infinity) <= maxBytes) return out.uri;
    }
  }
  throw new Error('photo-too-big');
}
