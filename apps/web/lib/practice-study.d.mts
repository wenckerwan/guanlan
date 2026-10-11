export type PracticeFilter = { module: string; type: string; status: string; keyword: string }
export type PracticeFilterQuestion = { questionRef: string; module: string; type: string; stem: string; options: Record<string, string>; event?: string; knowledge?: string }
export type PracticeFilterState = { attemptCount?: number; lastIsRight?: boolean }
export function isPracticeBank(value: unknown): boolean
export function filterPracticeQuestions<T extends PracticeFilterQuestion>(questions: T[], filter: PracticeFilter, states: Record<string, PracticeFilterState>, favorites: string[]): T[]
export function pagePracticeQuestions<T>(items: T[], requestedPage: number, pageSize?: number): { items: T[]; page: number; pages: number; total: number }
export function outboxKey(userId: string | number, bankId: string, contentVersion: string): string
export function readOutbox<T extends { eventId: string }>(storage: Storage, key: string): T[]
export function enqueueAttempt<T extends { eventId: string }>(storage: Storage, key: string, event: T): T[]
export function removeAttempt<T extends { eventId: string }>(storage: Storage, key: string, eventId: string): T[]
