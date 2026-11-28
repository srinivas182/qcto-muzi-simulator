import AsyncStorage from '@react-native-async-storage/async-storage';
import { Directory, File, Paths } from 'expo-file-system';
import type { ContentAssetItem, LearnLesson, LearnScenario } from '@simuwork/core';
import { api, ApiError } from './api';

/**
 * Offline packs (Sprint 4.6c): a whole programme saved on the phone, so lessons and simulators work with no data.
 *
 * The pack is fetched once (ideally on Wi-Fi) and kept in the app's documents folder with its pictures, audio and
 * the 3D view. "Check for updates" sends the pack's version; the server answers 304 when nothing changed, so an
 * update check costs almost nothing.
 */
export interface Pack {
  format: number;
  enrolment_id: string;
  locale: string;
  version: string;
  built_at: string;
  bytes: number;
  lessons: Record<string, Omit<LearnLesson, 'assets'> & { assets: Record<string, ContentAssetItem> }>;
  scenarios: Record<string, Omit<LearnScenario, 'results'>>;
  assets: ContentAssetItem[];
  runtime: { url: string; scenes: string[] };
}
export interface PackInfo { version: string; locale: string; bytes: number; saved_at: string; has3d: boolean }

const INDEX = 'sw.packs';
const root = () => new Directory(Paths.document, 'packs');
const packFile = (eid: string) => new File(root(), `${eid}.json`);
const assetFile = (a: ContentAssetItem) => new File(root(), `asset-${a.id}`);
export const runtimeFile = () => new File(root(), 'sim-3d.html');

async function index(): Promise<Record<string, PackInfo>> {
  try { return JSON.parse((await AsyncStorage.getItem(INDEX)) ?? '{}') as Record<string, PackInfo>; } catch { return {}; }
}

export async function packInfo(eid: string): Promise<PackInfo | null> {
  return (await index())[eid] ?? null;
}

/**
 * Download (or update) the pack. Returns 'saved' or 'up-to-date'. Files already on the phone are not fetched again.
 * `onProgress` gets a 0–1 fraction.
 */
export async function downloadPack(eid: string, locale: string, onProgress?: (f: number) => void): Promise<'saved' | 'up-to-date'> {
  const current = await packInfo(eid);
  let pack: Pack;
  try {
    pack = (await api<{ data: Pack }>(`/learn/enrolments/${eid}/pack?locale=${locale}`, { headers: current && current.locale === locale && packFile(eid).exists ? { 'If-None-Match': `"${current.version}"` } : {} })).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 304) return 'up-to-date';
    throw e;
  }
  root().create({ idempotent: true, intermediates: true });
  const files: { url: string; to: File }[] = pack.assets.map((a) => ({ url: a.url, to: assetFile(a) }));
  files.push({ url: pack.runtime.url, to: runtimeFile() });
  let done = 0;
  let has3d = true;
  for (const f of files) {
    const isRuntime = f.to.uri === runtimeFile().uri;
    try {
      // Pictures and audio never change under the same id; the 3D view is refreshed with every pack.
      if (!f.to.exists || isRuntime) await File.downloadFileAsync(f.url, f.to, { idempotent: true });
    } catch {
      if (isRuntime) has3d = runtimeFile().exists; // keep an older 3D view if the new one failed
      else throw new ApiError(0, 'offline');
    }
    onProgress?.(++done / files.length);
  }
  await packFile(eid).write(JSON.stringify(pack));
  const all = await index();
  all[eid] = { version: pack.version, locale: pack.locale, bytes: pack.bytes, saved_at: new Date().toISOString(), has3d };
  await AsyncStorage.setItem(INDEX, JSON.stringify(all));
  return 'saved';
}

export async function loadPack(eid: string): Promise<Pack | null> {
  const f = packFile(eid);
  if (!f.exists) return null;
  try {
    const pack = JSON.parse(await f.text()) as Pack;
    // Point pictures and audio at the copies on the phone.
    const local = (a: ContentAssetItem) => (assetFile(a).exists ? { ...a, url: assetFile(a).uri } : a);
    for (const l of Object.values(pack.lessons)) l.assets = Object.fromEntries(Object.entries(l.assets).map(([k, a]) => [k, local(a)]));
    return pack;
  } catch {
    return null;
  }
}

export async function removePack(eid: string): Promise<void> {
  const f = packFile(eid);
  if (f.exists) f.delete();
  const all = await index();
  delete all[eid];
  await AsyncStorage.setItem(INDEX, JSON.stringify(all));
}

/** Online first; when there is no connection, use the pack on the phone. */
export async function onlineOrPack<T>(eid: string, online: () => Promise<T>, fromPack: (p: Pack) => T | undefined): Promise<T> {
  try {
    return await online();
  } catch (e) {
    if (!(e instanceof ApiError) || e.status !== 0) throw e;
    const pack = await loadPack(eid);
    const found = pack ? fromPack(pack) : undefined;
    if (found === undefined) throw e;
    return found;
  }
}
