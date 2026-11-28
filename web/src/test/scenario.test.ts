import { describe, expect, it } from 'vitest';
import { scoreRun, simReducer, startRun, type ScenarioContent, type SimAction, type SimState } from '@simuwork/core';

const base = { hints: [], why: '', outcome: null, lesson: null, critical: false, sets: {}, wrong: [] };
const content: ScenarioContent = {
  scene: 'pc-bench', pass_mark: 70, job: { minutes: 10, customer: 'Thandi' },
  steps: [
    { ...base, id: 'unplug', type: 'tap', target: 'wall-switch', prompt: 'Make it safe.', critical: true, hints: ['At the wall.'], sets: { 'wall-switch': 'off' },
      wrong: [{ on: 'case-panel', effect: 'spark', message: 'You opened a live PC.' }] },
    { ...base, id: 'cause', type: 'choose', prompt: 'Cause?', options: ['PSU', 'RAM'], answer: 1 },
    { ...base, id: 'fitram', type: 'place', target: 'ram-new', slot: 'slot-a1', prompt: 'Fit it.', sets: { 'ram-new': 'in' } },
  ],
};
const play = (mode: 'practice' | 'job', actions: SimAction[]): SimState => actions.reduce((s, a) => simReducer(content, s, a), startRun(content, mode));

describe('simulator runtime', () => {
  it('walks the steps, changes the scene and records every move', () => {
    const s = play('practice', [
      { type: 'tap', object: 'wall-switch', t: 1 }, { type: 'choose', index: 1, t: 2 },
      { type: 'tap', object: 'ram-new', t: 3 }, { type: 'tap', object: 'slot-a1', t: 4 },
    ]);
    expect(s.done).toBe(true);
    expect(s.scene).toMatchObject({ 'wall-switch': 'off', 'ram-new': 'in' });
    expect(s.events.map((e) => `${e.action}:${e.value}`)).toEqual(['tap:wall-switch', 'choose:1', 'place:ram-new>slot-a1']);
    expect(scoreRun(content, s.events, 'practice')).toMatchObject({ completed: true, score: 100, passed: true });
  });

  it('shows the consequence of a wrong move and keeps the learner on the step', () => {
    const s = play('job', [{ type: 'tap', object: 'case-panel', t: 1 }]);
    expect(s.step).toBe(0);
    expect(s.feedback).toEqual({ kind: 'wrong', message: 'You opened a live PC.', effect: 'spark' });
    expect(s.critical).toBe(true);
  });

  it('gives hints in practice only, one more each time, and they cost a little', () => {
    const practice = play('practice', [{ type: 'hint', t: 0 }, { type: 'hint', t: 0 }]);
    expect(practice.hints).toEqual({ unplug: 1 }); // only one hint written for this step
    expect(play('job', [{ type: 'hint', t: 0 }]).events).toEqual([]);
  });

  it('scores exactly like the server (same cases as AttemptSyncTest)', () => {
    const right = [{ step: 'unplug', action: 'tap', value: 'wall-switch', t: 0 }, { step: 'cause', action: 'choose', value: '1', t: 0 }, { step: 'fitram', action: 'place', value: 'ram-new>slot-a1', t: 0 }] as const;
    expect(scoreRun(content, [right[2]], 'practice')).toMatchObject({ completed: false, score: 0 });
    expect(scoreRun(content, [{ step: 'unplug', action: 'hint', t: 0 }, right[0], { step: 'cause', action: 'choose', value: '0', t: 0 }, right[1], right[2]], 'practice')).toMatchObject({ score: 87, passed: true });
    expect(scoreRun(content, [{ step: 'unplug', action: 'tap', value: 'case-panel', t: 0 }, ...right], 'job')).toMatchObject({ score: 92, passed: false, critical: true });
  });
});
