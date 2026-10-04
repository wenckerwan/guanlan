export function safeQueue(queue, userId) {
  return queue.filter(
    (e) =>
      e.userId === userId &&
      ["visit", "mastery", "favorite", "note", "recall", "resume"].includes(
        e.type,
      ),
  );
}
export function recallSet(nodes, start, size = 3) {
  return nodes.length
    ? Array.from(
        { length: Math.min(size, nodes.length) },
        (_, i) => nodes[(start + i) % nodes.length],
      )
    : [];
}
export function maskConcept(node) {
  return node.summary.split(node.title).join("____");
}
export function latestDue(recalls, now) {
  const latest = new Map();
  for (const r of recalls)
    if (!latest.has(r.nodeId) || r.at >= latest.get(r.nodeId).at)
      latest.set(r.nodeId, r);
  return [...latest.values()].filter((r) => r.dueAt <= now);
}
export function attemptIntent(previous, input, eventId) {
  return previous || { eventId, ...input };
}
export function previewImport(raw) {
  function check(x) {
    if (!x || typeof x !== "object") return;
    for (const k of Object.keys(x)) {
      if (["__proto__", "constructor", "prototype"].includes(k))
        throw Error("包含不允许的字段");
      check(x[k]);
    }
  }
  if (!raw || typeof raw !== "object" || Array.isArray(raw))
    throw Error("备份应为 JSON 对象");
  check(raw);
  const p = raw.progress || raw.state || raw;
  if (!p.nodes || Array.isArray(p.nodes)) throw Error("缺少知识点记录");
  return {
    nodes: Object.keys(p.nodes).length,
    attempts: (p.attempts || []).length,
    version: raw.version || raw.format || "V2",
  };
}
