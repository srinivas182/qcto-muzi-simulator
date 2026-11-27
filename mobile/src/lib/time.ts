import type { MessageKey } from '@simuwork/core';

export function greetingKey(hour: number): MessageKey {
  return hour < 12 ? 'home.morning' : hour < 17 ? 'home.afternoon' : 'home.evening';
}
