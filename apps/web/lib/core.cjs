(function (root, factory) {
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.MayuanCore = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';
  const masteryStates = ['unlearned', 'fuzzy', 'mastered'];
  const unsafe = new Set(['__proto__', 'constructor', 'prototype']);
  const clone = value => JSON.parse(JSON.stringify(value));
  const timestamp = value => typeof value === 'number' && Number.isFinite(value) && value >= 0;
  function searchNodes(nodes, query) {
    const words = String(query).trim().toLowerCase().slice(0, 256).split(/\s+/).filter(Boolean);
    return nodes.map((node, index) => {
      const title = node.title.toLowerCase();
      const text = [node.title, node.summary, node.detail, node.trap, node.method, ...(node.keywords || [])].join(' ').toLowerCase();
      return {node, index, match: words.every(w => text.includes(w)), score: words.reduce((s,w) => s + (title === w ? 10 : title.includes(w) ? 5 : 0),0)};
    }).filter(n => n.match).sort((a,b) => b.score-a.score || a.index-b.index).map(n => n.node);
  }
  function emptyProgress() { return {version: 1, updatedAt: 0, nodes: {}}; }
  function emptyNode() {
    return {visited: false, mastery: 'unlearned', masteryUpdatedAt: 0, updatedAt: 0, answers: {}, attempts: 0, correct: 0, lastAnswered: null, lastReason: null};
  }
  function safeObject(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('进度格式应为对象');
    for (const key of Object.keys(value)) {
      if (unsafe.has(key)) throw new Error('进度包含不允许的字段');
      if (value[key] && typeof value[key] === 'object') safeObject(value[key]);
    }
  }
  function recount(entry) {
    const answers = Object.values(entry.answers).sort((a,b) => a.at-b.at);
    entry.attempts = answers.length;
    entry.correct = answers.filter(a => a.correct).length;
    entry.lastAnswered = answers.length ? answers.at(-1).at : null;
    entry.lastReason = answers.length && !answers.at(-1).correct ? answers.at(-1).reason : null;
    return entry;
  }
  function validateProgress(raw, nodeIds) {
    safeObject(raw);
    if (raw.version !== 1 || !timestamp(raw.updatedAt) || !raw.nodes || Object.keys(raw.nodes).length > nodeIds.length) throw new Error('进度版本或节点数量无效');
    const known = new Set(nodeIds);
    const clean = emptyProgress(); clean.updatedAt = raw.updatedAt;
    for (const [id, entry] of Object.entries(raw.nodes)) {
      if (!known.has(id)) throw new Error('进度包含未知知识点：'+id);
      if (!entry || typeof entry.visited !== 'boolean' || !masteryStates.includes(entry.mastery) || !timestamp(entry.updatedAt) || !entry.answers || Array.isArray(entry.answers)) throw new Error('知识点进度格式无效：'+id);
      if (Object.keys(entry.answers).length > 10000) throw new Error('单个知识点记录过多');
      const masteryUpdatedAt = entry.masteryUpdatedAt === undefined ? 0 : entry.masteryUpdatedAt;
      if (!timestamp(masteryUpdatedAt)) throw new Error('自评时间无效');
      const out = {...emptyNode(), visited:entry.visited, mastery:entry.mastery, masteryUpdatedAt, updatedAt:entry.updatedAt};
      for (const [key, a] of Object.entries(entry.answers)) {
        if (!/^[\w-]{1,100}$/.test(key) || !a || typeof a.correct !== 'boolean' || !timestamp(a.at) || typeof a.exercise !== 'string' || a.exercise.length > 100 || typeof a.reason !== 'string' || a.reason.length > 100) throw new Error('练习记录无效');
        out.answers[key] = {exercise:a.exercise, correct:a.correct, reason:a.reason, at:a.at};
      }
      recount(out);
      if (entry.attempts !== out.attempts || entry.correct !== out.correct || entry.lastAnswered !== out.lastAnswered || entry.lastReason !== out.lastReason) throw new Error('练习统计与记录不一致');
      clean.nodes[id] = out;
    }
    return clean;
  }
  function recordAnswer(progress, ids, answer) {
    if (!/^[\w-]{1,100}$/.test(answer.id) || typeof answer.correct !== 'boolean' || !timestamp(answer.at)) throw new Error('回答格式无效');
    const out = clone(progress);
    for (const id of new Set(ids)) {
      if (unsafe.has(id)) throw new Error('知识点无效');
      const entry = out.nodes[id] || emptyNode();
      if (!Object.hasOwn(entry.answers, answer.id)) {
        entry.answers[answer.id] = {exercise:answer.exercise, correct:answer.correct, reason:answer.reason || '', at:answer.at};
        entry.visited = true;
        entry.updatedAt = Math.max(entry.updatedAt, answer.at);
        out.nodes[id] = recount(entry);
      }
    }
    out.updatedAt = Math.max(out.updatedAt, answer.at);
    return out;
  }
  function mergeProgress(local, incoming, nodeIds) {
    const a = validateProgress(local, nodeIds), b = validateProgress(incoming, nodeIds);
    const out = emptyProgress(); out.updatedAt = Math.max(a.updatedAt,b.updatedAt);
    for (const id of new Set([...Object.keys(a.nodes),...Object.keys(b.nodes)])) {
      const x=a.nodes[id] || emptyNode(), y=b.nodes[id] || emptyNode();
      const latest = y.masteryUpdatedAt > x.masteryUpdatedAt || (y.masteryUpdatedAt === x.masteryUpdatedAt && x.mastery === 'unlearned' && y.mastery !== 'unlearned') ? y : x;
      const entry = {...emptyNode(), visited:x.visited || y.visited, mastery:latest.mastery, masteryUpdatedAt:latest.masteryUpdatedAt, updatedAt:Math.max(x.updatedAt,y.updatedAt), answers:clone(x.answers)};
      for (const [key, value] of Object.entries(y.answers)) if (!entry.answers[key] || value.at > entry.answers[key].at) entry.answers[key] = clone(value);
      out.nodes[id] = recount(entry);
    }
    return out;
  }
  function valueModel(mode, factor) {
    if (!['social','individual'].includes(mode) || !Number.isFinite(factor) || factor <= 0) throw new Error('生产率参数无效');
    const unit = mode==='social' ? 10/factor : 10;
    return {unit, quantity:factor, total:unit*factor};
  }
  function phaseModel(temperature) {
    if (!Number.isFinite(temperature)) throw new Error('温度无效');
    const phase = temperature<0 ? '固态' : temperature===0 ? '固态与液态共存' : temperature<100 ? '液态' : temperature===100 ? '液态与气态共存' : '气态';
    return {phase, temperature, boundary:'纯水、标准大气压下的平衡相态示意；相变还需要吸收或释放潜热。温度不是所有事物发生质变的统一阈值。'};
  }
  function productionModel(technology, organization) {
    if (!['manual','industrial','digital'].includes(technology) || !['rigid','adaptive'].includes(organization)) throw new Error('情境无效');
    const scenarios = {
      manual:'小规模手工作业需要与劳动者技能和工具条件相适应的组织。',
      industrial:'机械化协作需要相应的分工、协调和管理。',
      digital:'数字化协同需要适应新技术条件的数据协作、技能培养与组织方式。'
    };
    return {result:organization==='adaptive'?'适应生产力状况，促进生产发展':'组织方式僵化，需要调整', explanation:scenarios[technology]+(organization==='adaptive'?'适合生产力状况的生产关系对生产力发展起促进作用。':'不适合生产力状况的生产关系对生产力发展起阻碍作用。'), boundary:'本实验只示意生产关系中的组织与管理因素。生产关系还包括生产资料所有制和分配关系；不能用一个企业管理案例代替社会制度分析。'};
  }
  function recallClue(node) { return node.summary.split(node.title).join('这一概念'); }
  function pickFramework(nodes, anchorId, size = 3) {
    const start = Math.max(0,nodes.findIndex(n => n.id === anchorId));
    return Array.from({length:Math.min(size,nodes.length)},(_,i) => nodes[(start+i)%nodes.length]);
  }
  function graphEdge(a,b,star=false,bend=40) {
    const dx=b.x-a.x,dy=b.y-a.y,length=Math.hypot(dx,dy)||1;
    const control={x:(a.x+b.x)/2-dy/length*bend,y:(a.y+b.y)/2+dx/length*bend};
    function boundary(center){
      const vx=control.x-center.x,vy=control.y-center.y,norm=Math.hypot(vx,vy)||1;
      const ux=vx/norm,uy=vy/norm;
      const distance=star?39:Math.min(108/Math.max(Math.abs(ux),.0001),35/Math.max(Math.abs(uy),.0001))+4;
      return {x:center.x+ux*distance,y:center.y+uy*distance};
    }
    const start=boundary(a),end=boundary(b);
    return {start,end,control,label:{x:(start.x+2*control.x+end.x)/4,y:(start.y+2*control.y+end.y)/4-6}};
  }
  return {searchNodes,emptyProgress,emptyNode,validateProgress,recordAnswer,mergeProgress,valueModel,phaseModel,productionModel,masteryStates,recallClue,pickFramework,graphEdge};
});
