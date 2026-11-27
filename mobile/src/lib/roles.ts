import type { Session } from '@simuwork/core';

/** Learners use the learning tabs; everyone else is pointed to the web portal for now. */
export const isLearner = (s: Session) => s.permissions.includes('learning.use');
