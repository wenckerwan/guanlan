import type { StudyRepository, StudyState, ViewState } from '../core/index';
import { accessRecord } from './indexed-db';
import { parseBackup, validateStudyState } from './validation';

/** 观澜学习接口返回的收藏条目（history_event / history_comparison）。 */
interface RemoteFavorite { id: number | string; targetType: string; targetId: string; title?: string; url?: string; updatedAt?: string }
/** 观澜学习接口返回的笔记条目。 */
interface RemoteNote { id: number | string; targetType: string; targetId: string; title?: string; content?: string; updatedAt?: string }
/** 观澜学习进度条目；史纲约定 scope='history'。 */
interface RemoteProgress { scope: string; ref: string; label?: string; status?: string; progress?: number; updatedAt?: string }

export type StudySyncStatus = 'online' | 'local' | 'auth';
export interface GuanlanStudyStatus {
  /** online：观澜为权威；local：离线/未登录，仅本地 IndexedDB；auth：token 失效已停写，本地保留待同步。 */
  mode: StudySyncStatus;
  /** 是否有本地改动尚未并入观澜。 */
  pendingSync: boolean;
  warning: string | null;
}

export interface GuanlanStudyConfig {
  fetch: typeof fetch;
  /** 观澜宿主提供（读 guanlan.token）；返回空/无 Authorization 表示未登录。 */
  authHeaders?: () => HeadersInit | Promise<HeadersInit>;
  /** 观澜 API 前缀，默认 ''（相对，同源）；不可带路径以外的 query/hash。 */
  apiBase?: string;
  /** 由宿主向服务端确认的账号ID；缺省为独立游客分区。 */
  accountId?: number | string | null;
  /** Cookie/宿主身份变化时立即停用旧仓库。 */
  isCurrent?: () => boolean;
  contentVersion?: string;
  /** 未登录或 401 时由宿主决定跳转（如 /login?redirect=...）；不注入则仅提示。 */
  onAuthRequired?: () => void;
}

const HISTORY_TYPES = new Set(['history_event', 'history_comparison']);
const LEGACY_DATABASE = 'history-study-guanlan-v1';
export function historyStorageName(accountId?: number | string | null): string {
  if (accountId == null) return 'history-study-guanlan-v2:guest';
  if (!/^[1-9]\d*$/.test(String(accountId))) throw new Error('史纲账号标识无效');
  return `history-study-guanlan-v2:user:${accountId}`;
}

/** 仅主动导出旧共享库；不清理、不自动归入任何账号。 */
export async function exportLegacyHistory(): Promise<string | null> {
  const state = await accessRecord(LEGACY_DATABASE, 'study', 'state', 'readonly', value => value === undefined ? null : validateStudyState(value));
  return state ? JSON.stringify({ version: 1, contentVersion: 'history.v1', exportedAt: new Date().toISOString(), state }, null, 2) : null;
}
const PENDING_KEY = 'pending-sync';
/** 本地“上次与观澜一致”的 bookmarks/notes 基线，用于跨实例计算增量写。 */
const BASE_KEY = 'remote-base';
type PendingStudy = { bookmarks: string[]; notes: Record<string, string>; view: ViewState | null; revision?: string };

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
function unwrapList(value: unknown): Record<string, unknown>[] {
  const data = isRecord(value) && 'data' in value ? value.data : value;
  const list = Array.isArray(data) ? data : isRecord(data) && Array.isArray(data.list) ? data.list : null;
  if (!list) throw new Error('观澜学习接口响应缺少 data 列表');
  return list.filter(isRecord);
}

/**
 * 观澜权威学习仓库：收藏/笔记/进度读写走 /api/v1/study/*；
 * attempts（排序/回忆作答）始终只留本地 IndexedDB，不写入观澜（§2.5）。
 * 观澜不可用、未登录或 401 时降级本地读写并标记待同步；恢复在线后以观澜为权威做一次性合并。
 */
export class GuanlanStudyRepository implements StudyRepository {
  readonly status: GuanlanStudyStatus = { mode: 'local', pendingSync: false, warning: null };
  private readonly apiBase: string;
  private readonly localName: string;
  private readonly contentVersion: string;
  private queue: Promise<unknown> = Promise.resolve();
  private readonly boundHeaders: Promise<Headers>;
  private readonly controller = new AbortController();
  /** 内存中的远端快照：bookmarks/notes 用于计算增量写，updatedAt 用于冲突取新。 */
  private remote: { bookmarks: Set<string>; notes: Map<string, { id: number | string; content: string; updatedAt: string }> } | null = null;
  private lastSavedLocal: { bookmarks: string[]; notes: Record<string, string> } | null = null;

  constructor(private readonly config: GuanlanStudyConfig) {
    this.apiBase = (config.apiBase ?? '').replace(/\/+$/, '');
    if (/[?#]/.test(this.apiBase)) throw new Error('apiBase 不可带 query 或 hash');
    this.localName = historyStorageName(config.accountId);
    this.contentVersion = config.contentVersion ?? 'history.v1';
    // 凭证只在构造时读取一次；旧仓库永远不能借用新账号的凭证。
    this.boundHeaders = Promise.resolve(config.accountId == null ? undefined : config.authHeaders?.()).then(value => new Headers(value));
  }

  dispose(): void { this.controller.abort(); }

  private assertCurrent(): void {
    if (this.controller.signal.aborted || this.config.isCurrent?.() === false) throw new Error('账号已变化，请重新打开史纲');
  }

  private enqueue<T>(operation: () => Promise<T>, preserveLocal = false): Promise<T> {
    const next = this.queue.catch(() => undefined).then(async () => {
      if (!preserveLocal) this.assertCurrent();
      const result = await operation();
      this.assertCurrent();
      return result;
    });
    this.queue = next;
    void next.finally(() => { if (this.queue === next) this.queue = Promise.resolve(); }).catch(() => undefined);
    return next;
  }

  private async headers(): Promise<HeadersInit | undefined> {
    this.assertCurrent();
    const headers = new Headers(await this.boundHeaders);
    this.assertCurrent();
    return headers;
  }
  private hasToken(headers: HeadersInit | undefined): boolean {
    if (!headers) return false;
    return new Headers(headers).has('Authorization');
  }
  private async request(path: string, init: RequestInit = {}, allowFail = false): Promise<Response> {
    const headers = new Headers(await this.headers() ?? {});
    if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
    this.assertCurrent();
    const response = await this.config.fetch(`${this.apiBase}${path}`, { ...init, headers, signal: this.controller.signal });
    this.assertCurrent();
    if (response.status === 401) {
      this.status.mode = 'auth';
      this.status.warning = '登录已失效，已暂停同步；本地记录保留，重新登录后合并';
      this.config.onAuthRequired?.();
      throw new Error('观澜登录已失效（401），已停写并保留本地');
    }
    if (!response.ok && !allowFail) throw new Error(`观澜学习接口失败：HTTP ${response.status} ${path}`);
    return response;
  }

  // ---------- 本地 IndexedDB（降级缓存 + attempts 唯一存储） ----------
  private async readLocal(): Promise<StudyState> {
    return accessRecord(this.localName, 'study', 'state', 'readonly', value => value === undefined ? emptyState() : validateStudyState(value));
  }
  private async writeLocal(state: StudyState): Promise<void> {
    const snapshot = validateStudyState(state);
    await accessRecord(this.localName, 'study', 'state', 'readwrite', (_value, store) => { store.put(snapshot, 'state'); });
  }
  private async readPending(): Promise<PendingStudy | null> {
    try {
      return await accessRecord(this.localName, 'study', PENDING_KEY, 'readonly', value => {
        if (value === undefined) return null;
        if (!isRecord(value)) return null;
        return {
          bookmarks: Array.isArray(value.bookmarks) ? value.bookmarks.filter((id): id is string => typeof id === 'string') : [],
          notes: isRecord(value.notes) ? Object.fromEntries(Object.entries(value.notes).filter(([, v]) => typeof v === 'string')) as Record<string, string> : {},
          view: isRecord(value.view) ? (value.view as unknown as ViewState) : null,
          ...(typeof value.revision === 'string' ? { revision: value.revision } : {}),
        };
      });
    } catch (error) { throw error; }
  }
  private async writePending(pending: { bookmarks: string[]; notes: Record<string, string>; view: ViewState | null } | null): Promise<void> {
    await accessRecord(this.localName, 'study', PENDING_KEY, 'readwrite', (_value, store) => {
      if (pending === null) store.delete(PENDING_KEY); else store.put(pending, PENDING_KEY);
    });
  }
  private async readBase(): Promise<{ bookmarks: string[]; notes: Record<string, string> }> {
    try {
      return await accessRecord(this.localName, 'study', BASE_KEY, 'readonly', value => {
        if (!isRecord(value)) return { bookmarks: [], notes: {} };
        return {
          bookmarks: Array.isArray(value.bookmarks) ? value.bookmarks.filter((id): id is string => typeof id === 'string') : [],
          notes: isRecord(value.notes) ? Object.fromEntries(Object.entries(value.notes).filter(([, v]) => typeof v === 'string')) as Record<string, string> : {},
        };
      });
    } catch (error) { throw error; }
  }
  private async writeBase(base: { bookmarks: string[]; notes: Record<string, string> }): Promise<void> {
    await accessRecord(this.localName, 'study', BASE_KEY, 'readwrite', (_value, store) => { store.put({ bookmarks: base.bookmarks, notes: base.notes }, BASE_KEY); });
  }

  // ---------- 远端聚合 ----------
  // favorites/notes 是关键读写目标：失败必须向上抛，避免把“拉取失败”误判为“远端为空”而删数据；progress 可降级。
  private async fetchRemote(): Promise<{ bookmarks: string[]; notes: Record<string, string>; view: ViewState | null; favoriteRows: RemoteFavorite[]; noteRows: RemoteNote[] }> {
    const [favoritesRes, notesRes, progressRes] = await Promise.all([
      this.request('/api/v1/study/favorites?targetType=history_event'),
      this.request('/api/v1/study/notes?targetType=history_event'),
      this.request('/api/v1/study/progress', {}, true),
    ]);
    const favorites = unwrapList(await favoritesRes.json());
    const notes = unwrapList(await notesRes.json());
    const progress = progressRes.ok ? unwrapList(await progressRes.json().catch(() => [])) : [];
    const favoriteRows = favorites as unknown as RemoteFavorite[];
    const noteRows = notes as unknown as RemoteNote[];
    const bookmarks = favoriteRows.filter(row => HISTORY_TYPES.has(String(row.targetType))).map(row => String(row.targetId));
    const notesMap: Record<string, string> = {};
    const noteMeta = new Map<string, { id: number | string; content: string; updatedAt: string }>();
    for (const row of noteRows) {
      if (!HISTORY_TYPES.has(String(row.targetType))) continue;
      const targetId = String(row.targetId);
      const updatedAt = String(row.updatedAt ?? '');
      const existing = noteMeta.get(targetId);
      if (!existing || updatedAt > existing.updatedAt) {
        noteMeta.set(targetId, { id: row.id, content: String(row.content ?? ''), updatedAt });
        notesMap[targetId] = String(row.content ?? '');
      }
    }
    let view: ViewState | null = null;
    for (const row of progress as unknown as RemoteProgress[]) {
      if (row.scope !== 'history' || typeof row.ref !== 'string') continue;
      if (row.ref.startsWith('view:') && row.label) {
        try {
          const parsed = JSON.parse(row.label) as unknown;
          if (isRecord(parsed)) view = parsed as unknown as ViewState;
        } catch { /* 忽略非法视图 */ }
      }
    }
    this.remote = { bookmarks: new Set(bookmarks), notes: noteMeta };
    return { bookmarks: [...new Set(bookmarks)], notes: notesMap, view, favoriteRows, noteRows };
  }

  /** 拉取远端并严格校验整体响应可用性；任何 401/网络错误向上抛。 */
  private async loadRemoteState(): Promise<StudyState | null> {
    const headers = await this.headers();
    if (!this.hasToken(headers)) return null; // 未登录：只读免登录，学习记录仅本地
    const remote = await this.fetchRemote();
    const local = await this.readLocal();
    return validateStudyState({
      version: 1,
      bookmarks: remote.bookmarks,
      notes: remote.notes,
      attempts: local.attempts, // attempts 永远只走本地
      view: remote.view ?? local.view,
    });
  }

  async load(): Promise<StudyState> {
    return this.enqueue(() => this.loadPendingSnapshot());
  }

  private async loadPendingSnapshot(): Promise<StudyState> {
      try {
        const remote = await this.loadRemoteState();
        if (remote === null) {
          this.status.mode = 'local';
          this.status.warning = '未登录：学习记录仅保存在本机，登录后同步到观澜';
          return this.readLocal();
        }
        // 恢复在线：如有待同步本地改动，按已确认基线重放本机变更，保留冲突草稿。
        const pending = await this.readPending();
        const result = pending ? await this.mergePending(remote, pending) : { state: remote, conflicts: false };
        const merged = result.state;
        // Compare and commit atomically: another tab may save while network replay waits.
        const committed = await accessRecord(this.localName, 'study', PENDING_KEY, 'readwrite', (current, store) => {
          if (JSON.stringify(current ?? null) !== JSON.stringify(pending)) return false;
          store.put(merged, 'state');
          if (!result.conflicts) {
            store.put({ bookmarks: merged.bookmarks, notes: merged.notes }, BASE_KEY);
            store.delete(PENDING_KEY);
          }
          return true;
        });
        if (!committed) {
          this.status.mode = 'local'; this.status.pendingSync = true;
          this.status.warning = '其他页面有较新的本机记录，已保留，等待下次同步';
          return this.readLocal();
        }
        this.status.mode = 'online';
        this.status.pendingSync = result.conflicts;
        this.status.warning = result.conflicts ? '同步冲突：本机笔记已保留，可导出备份；线上笔记未覆盖' : null;
        this.lastSavedLocal = { bookmarks: [...merged.bookmarks], notes: { ...merged.notes } };
        return merged;
      } catch (error) {
        this.assertCurrent();
        const local = await this.readLocal();
        if (this.status.mode !== 'auth') {
          this.status.mode = 'local';
          this.status.warning = error instanceof Error && error.message.includes('20000') ? error.message : '观澜不可用，本地保存，联网后同步';
        }
        this.status.pendingSync = true;
        return local;
      }
  }

  /** 以已确认基线比较本地与远端，逐项记录成功写入，冲突不覆盖任何一方。 */
  private async mergePending(remote: StudyState, pending: { bookmarks: string[]; notes: Record<string, string>; view: ViewState | null }): Promise<{ state: StudyState; conflicts: boolean }> {
    const base = await this.readBase();
    const nextBookmarks = new Set(pending.bookmarks);
    for (const id of nextBookmarks) {
      if (!remote.bookmarks.includes(id) && !base.bookmarks.includes(id)) {
        await this.putFavorite(id, true); base.bookmarks.push(id); await this.writeBase(base);
      }
    }
    for (const id of [...base.bookmarks]) {
      if (!nextBookmarks.has(id) && remote.bookmarks.includes(id)) {
        await this.putFavorite(id, false); base.bookmarks = base.bookmarks.filter(value => value !== id); await this.writeBase(base);
      }
    }
    const conflicts: string[] = [];
    for (const id of new Set([...Object.keys(base.notes), ...Object.keys(pending.notes)])) {
      const before = base.notes[id] ?? '';
      const after = pending.notes[id] ?? '';
      if (before === after) continue;
      const existing = this.remote?.notes.get(id);
      // Retry-safe: apply a local change only while the server still matches its base.
      // Changes from another device remain authoritative.
      if ((existing?.content ?? '') !== before && (existing?.content ?? '') !== after) { conflicts.push(id); continue; }
      if ((existing?.content ?? '') !== after) {
        if (!after.trim()) { if (existing) await this.deleteNote(existing.id); }
        else if (existing) await this.patchNote(existing.id, after);
        else await this.postNote(id, after);
      }
      if (after.trim()) base.notes[id] = after; else delete base.notes[id];
      await this.writeBase(base);
    }
    await this.postViewProgress(pending.view);
    const fresh = await this.fetchRemote();
    for (const id of conflicts) { if (id in pending.notes) fresh.notes[id] = pending.notes[id]; else delete fresh.notes[id]; }
    return { state: validateStudyState({ version: 1, bookmarks: fresh.bookmarks, notes: fresh.notes, attempts: remote.attempts, view: fresh.view ?? pending.view }), conflicts: conflicts.length > 0 };
  }

  // ---------- 写操作 ----------
  private async putFavorite(targetId: string, favorited: boolean): Promise<void> {
    await this.request('/api/v1/study/favorites', { method: 'PUT', body: JSON.stringify({ targetType: 'history_event', targetId, title: targetId, url: `/history/?event=${encodeURIComponent(targetId)}`, favorited }) });
  }
  private async postNote(targetId: string, content: string): Promise<void> {
    if (content.length > 20000) throw new Error('笔记超过 20000 字，完整内容已保存在本机；缩短后可同步');
    await this.request('/api/v1/study/notes', { method: 'POST', body: JSON.stringify({ targetType: 'history_event', targetId, title: targetId, content }) });
  }
  private async patchNote(id: number | string, content: string): Promise<void> {
    if (content.length > 20000) throw new Error('笔记超过 20000 字，完整内容已保存在本机；缩短后可同步');
    await this.request(`/api/v1/study/notes/${encodeURIComponent(String(id))}`, { method: 'PATCH', body: JSON.stringify({ content }) });
  }
  private async deleteNote(id: number | string): Promise<void> {
    const response = await this.request(`/api/v1/study/notes/${encodeURIComponent(String(id))}`, { method: 'DELETE' }, true);
    if (!response.ok && response.status !== 404) throw new Error(`笔记删除失败：HTTP ${response.status}`);
  }
  private async postViewProgress(view: ViewState | null): Promise<void> {
    if (!view) return;
    await this.request('/api/v1/study/progress', { method: 'POST', body: JSON.stringify({ scope: 'history', ref: 'view:timeline', label: JSON.stringify(view), status: 'reading', progress: 0 }) });
  }

  async save(state: StudyState): Promise<StudyState> {
    const input = validateStudyState(state);
    return this.enqueue(async () => {
      const snapshot = validateStudyState(input);
      // Reconcile against the current record in the write transaction, including other tabs.
      await accessRecord(this.localName, 'study', 'state', 'readwrite', (value, store) => {
      const localBefore = value === undefined ? emptyState() : validateStudyState(value);
      // Preserve reconciled fields that a queued UI snapshot has not edited.
      if (this.lastSavedLocal) {
        for (const id of new Set([...Object.keys(snapshot.notes), ...Object.keys(localBefore.notes), ...Object.keys(this.lastSavedLocal.notes)])) {
          if ((snapshot.notes[id] ?? '') === (this.lastSavedLocal.notes[id] ?? '')) {
            if (id in localBefore.notes) snapshot.notes[id] = localBefore.notes[id]; else delete snapshot.notes[id];
          }
        }
        const desired = new Set(snapshot.bookmarks);
        for (const id of new Set([...snapshot.bookmarks, ...localBefore.bookmarks, ...this.lastSavedLocal.bookmarks])) {
          if (desired.has(id) === this.lastSavedLocal.bookmarks.includes(id)) {
            if (localBefore.bookmarks.includes(id)) desired.add(id); else desired.delete(id);
          }
        }
        snapshot.bookmarks = [...desired];
      }
      const attempts = new Map(localBefore.attempts.map(attempt => [attempt.id, attempt]));
      for (const attempt of snapshot.attempts) if (!attempts.has(attempt.id)) attempts.set(attempt.id, attempt);
      snapshot.attempts = [...attempts.values()];
        store.put(validateStudyState(snapshot), 'state');
        store.put({ bookmarks: snapshot.bookmarks, notes: snapshot.notes, view: snapshot.view, revision: `${Date.now()}-${Math.random()}` }, PENDING_KEY);
      });
      this.assertCurrent();
      const headers = await this.headers();
      this.status.pendingSync = true;
      if (!this.hasToken(headers) || this.status.mode === 'auth') {
        this.lastSavedLocal = { bookmarks: [...input.bookmarks], notes: { ...input.notes } };
        this.status.mode = this.status.mode === 'auth' ? 'auth' : 'local';
        this.status.warning = this.status.mode === 'auth' ? this.status.warning : '本地保存，登录后同步';
        return snapshot;
      }
      const merged = await this.loadPendingSnapshot();
      this.lastSavedLocal = { bookmarks: [...input.bookmarks], notes: { ...input.notes } };
      return merged;
    }, true);
  }

  async exportBackup(): Promise<string> {
    const state = await this.load();
    const text = JSON.stringify({ version: 1, contentVersion: this.contentVersion, exportedAt: new Date().toISOString(), state }, null, 2);
    parseBackup(text);
    return text;
  }

  async importBackup(text: string): Promise<StudyState> {
    const imported = parseBackup(text).state;
    const existing = await this.load();
    const seen = new Set(existing.attempts.map(attempt => attempt.id));
    const attempts = [...existing.attempts];
    for (const attempt of imported.attempts) if (!seen.has(attempt.id)) { attempts.push(attempt); seen.add(attempt.id); }
    const merged = validateStudyState({ version: 1, bookmarks: [...new Set([...existing.bookmarks, ...imported.bookmarks])], notes: { ...imported.notes, ...existing.notes }, attempts, view: existing.view ?? imported.view });
    await this.save(merged);
    return merged;
  }
}

function emptyState(): StudyState {
  return { version: 1, bookmarks: [], notes: {}, attempts: [], view: null };
}
