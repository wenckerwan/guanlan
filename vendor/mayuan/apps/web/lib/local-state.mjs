import { previewImport } from './model.mjs';

const KEY = 'mayuan-v2-local-state';
const record = value => value !== null && typeof value === 'object' && !Array.isArray(value);
export function validateLocalStudyState(value) {
  previewImport(value); // 同时拒绝原型污染字段。
  if (!record(value.nodes) || Object.values(value.nodes).some(node => !record(node))) throw Error('知识点记录损坏');
  for (const key of ['attempts', 'recalls', 'historicalAnswers', 'notes']) {
    if (value[key] !== undefined && (!Array.isArray(value[key]) || value[key].some(item => !record(item)))) throw Error('学习记录格式无效');
  }
  if (value.favorites !== undefined && (!Array.isArray(value.favorites) || value.favorites.some(item => typeof item !== 'string'))) throw Error('收藏格式无效');
  if (value.resume !== undefined && !record(value.resume)) throw Error('恢复位置格式无效');
  return value;
}

export function createLocalStateStore({ storage, blank, normalize, onStatus = () => {}, setTimer = setTimeout, clearTimer = clearTimeout }) {
  let timer, latest, dirty = false, blockedRead = false, originalRaw = null;
  const report = (mode, message) => onStatus({ mode, message });
  function cancel() { if (timer !== undefined) clearTimer(timer); timer = undefined; }
  function fail() { report('error', '学习记录尚未保存。当前操作保留在本页，请导出备份或重试保存。'); }
  function write() {
    cancel();
    if (blockedRead) return false;
    if (!latest || !dirty) return true;
    try {
      storage().setItem(KEY, JSON.stringify(latest));
      dirty = false;
      report('saved', '学习记录已保存在当前浏览器');
      return true;
    } catch { fail(); return false; }
  }
  return {
    read() {
      let raw;
      try { raw = storage().getItem(KEY); }
      catch { blockedRead = true; fail(); return blank(); }
      try {
        const value = raw == null ? blank() : { ...blank(), ...validateLocalStudyState(JSON.parse(raw)) };
        latest = normalize(value);
        report('saved', '学习记录已保存在当前浏览器');
        return latest;
      } catch {
        blockedRead = true; originalRaw = raw;
        report('corrupt', '旧学习记录损坏，原始内容仍保留。新操作暂未保存；请先导出，再备份原记录并重试保存。');
        return blank();
      }
    },
    save(value, immediate = false) {
      latest = value; dirty = true;
      cancel();
      if (blockedRead) return false;
      if (immediate) return write();
      report('pending', '学习记录正在保存…');
      timer = setTimer(write, 300);
      return true;
    },
    flush: write,
    retry(value) {
      latest = value; dirty = true; cancel();
      if (blockedRead) {
        try {
          const currentRaw = storage().getItem(KEY);
          const recoveryKey = `${KEY}:recovery:${Date.now()}:${Math.random().toString(36).slice(2)}`;
          if (originalRaw != null) storage().setItem(recoveryKey, originalRaw);
          if (currentRaw != null && currentRaw !== originalRaw) storage().setItem(`${recoveryKey}:current`, currentRaw);
          // 任一原文备份失败均不允许覆盖主记录。
          blockedRead = false; originalRaw = null;
        } catch { fail(); return false; }
      }
      return write();
    },
    exportState(value, contentVersion) { return JSON.stringify({ version: 2, exportedAt: Date.now(), contentVersion, state: value }, null, 2); },
    get originalRaw() { return originalRaw; },
    dispose() { write(); cancel(); },
  };
}
