import { useEffect, useRef } from 'react';
import type { SimEffect } from '@simuwork/core';
import { Bench } from '../../sim/bench';

/** The 3D view of a simulator on the web (loaded only when a simulator opens; budget 150 KB gzip). */
export default function Scene3D({ scene, holding, effect, onTap, label }: {
  scene: Record<string, string>; holding: string | null; effect: { kind: SimEffect; key: number } | null;
  onTap: (id: string) => void; label: string;
}) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const world = useRef<Bench | null>(null);
  const tap = useRef(onTap);
  useEffect(() => { tap.current = onTap; }, [onTap]);

  useEffect(() => {
    const bench = new Bench(canvas.current!, (id) => tap.current(id));
    world.current = bench;
    return () => bench.dispose();
  }, []);
  useEffect(() => { world.current?.apply(scene, holding); }, [scene, holding]);
  useEffect(() => { if (effect) world.current?.flash(effect.kind); }, [effect]);

  return <canvas ref={canvas} role="img" aria-label={label} className="block h-full w-full touch-none select-none" />;
}

