<script setup lang="ts">
import core from "../lib/core.mjs";
import {
  recallSet,
  maskConcept,
  previewImport,
  latestDue,
  attemptIntent,
} from "../lib/model.mjs";
// 3D 星空组件懒加载：three.js 分包仅在进入星空视图时下载
const LazyUniverseScene = defineAsyncComponent(
  () => import("../app/components/UniverseScene.vue"),
);
const views = [
  ["home", "学习工作台"],
  ["map", "知识地图"],
  ["stars", "知识星空"],
  ["compare", "概念辨析"],
  ["labs", "原理实验"],
  ["recall", "主动回忆"],
  ["quiz", "原创练习"],
  ["notebook", "复习记录"],
];
const view = ref("home"),
  chapter = ref("all"),
  query = ref(""),
  selected = ref<any>(null),
  relation = ref<any>(null),
  content = ref<any>(null),
  state = ref<any>(null),
  loading = ref(true),
  busy = ref(false),
  error = ref(""),
  notice = ref("");
const webglFailed = ref(false); // 3D 星空 WebGL 失败 → 回退 SVG star 视图
const pendingAttempt = ref<any>(null);
// ---- 纯本地免登录:学习状态只存浏览器 localStorage ----
const LOCAL_KEY = "mayuan-v2-local-state";
/** 与后端 Application::blank() 同构;favorites/notes 保留为空占位(UI 已删除)。 */
function blankState() {
  return {
    revision: 0,
    nodes: {} as Record<string, any>,
    attempts: [] as any[],
    recalls: [] as any[],
    historicalAnswers: [] as any[],
    favorites: [] as string[],
    notes: [] as any[],
    resume: { view: "map", nodeId: null as string | null },
    sync: { status: "local", pending: 0 },
    summary: { visited: 0, mastered: 0, attempts: 0, correct: 0, due: 0 },
  };
}
function blankNode() {
  return { visited: false, mastery: "unlearned", masteryUpdatedAt: 0, updatedAt: 0 };
}
/** 每次写入后重算 summary(与原后端 /state 输出一致)。 */
function recomputeSummary(s: any) {
  const nodes = Object.values(s.nodes || {}) as any[];
  const latest = new Map<string, any>();
  for (const r of s.recalls || [])
    if (!latest.has(r.nodeId) || r.at >= latest.get(r.nodeId).at)
      latest.set(r.nodeId, r);
  const now = Date.now();
  s.summary = {
    visited: nodes.filter((n) => n.visited).length,
    mastered: nodes.filter((n) => n.mastery === "mastered").length,
    attempts: (s.attempts || []).length,
    correct: (s.attempts || []).filter((a: any) => a.correct).length,
    due: [...latest.values()].filter((r) => r.dueAt <= now).length,
  };
  return s;
}
function readLocalState() {
  try {
    const raw = localStorage.getItem(LOCAL_KEY);
    if (!raw) return blankState();
    return recomputeSummary({ ...blankState(), ...JSON.parse(raw) });
  } catch {
    return blankState();
  }
}
let persistTimer: ReturnType<typeof setTimeout> | undefined;
/** 防抖写 localStorage;revision 递增以沿用原结构。 */
function persistState(immediate = false) {
  if (!state.value) return;
  state.value.revision = (state.value.revision || 0) + 1;
  state.value.updatedAt = Date.now();
  recomputeSummary(state.value);
  const write = () =>
    localStorage.setItem(LOCAL_KEY, JSON.stringify(state.value));
  if (immediate) {
    clearTimeout(persistTimer);
    write();
    return;
  }
  clearTimeout(persistTimer);
  persistTimer = setTimeout(write, 300);
}
const ci = ref(0),
  judgment = ref(""),
  revealed = ref(false),
  qi = ref(0),
  choice = ref(-1),
  reason = ref(""),
  answer = ref<any>(null),
  recallMode = ref("concept"),
  recallIndex = ref(0),
  recallReveal = ref(false),
  recallText = ref(""),
  importRaw = ref<any>(null),
  importPreview = ref<any>(null);
const nodes = computed(() => content.value?.nodes || []),
  comparisons = computed(() => content.value?.comparisons || []),
  exercises = computed(() => content.value?.exercises || []);
const filtered = computed(() =>
  core.searchNodes(
    nodes.value.filter(
      (n: any) =>
        !!query.value.trim() ||
        chapter.value === "all" ||
        n.module === chapter.value,
    ),
    query.value,
  ),
);
const focused = computed(() =>
  selected.value
    ? nodes.value.filter(
        (n: any) =>
          n.id === selected.value.id ||
          content.value.relations.some(
            (r: any) =>
              (r.from === selected.value.id && r.to === n.id) ||
              (r.to === selected.value.id && r.from === n.id),
          ),
      )
    : filtered.value,
);
const visible = computed(() =>
  chapter.value === "all" && !selected.value && !query.value
    ? content.value.modules.flatMap((m: any) =>
        nodes.value.filter((n: any) => n.module === m.id).slice(0, 5),
      )
    : focused.value,
);
const positions = computed(() =>
  visible.value.map((n: any, i: number) => ({
    ...n,
    x: 70 + (i % 5) * 170,
    y: 70 + Math.floor(i / 5) * 105,
  })),
);
const edges = computed(
  () =>
    content.value?.relations.filter(
      (r: any) =>
        positions.value.some((n: any) => n.id === r.from) &&
        positions.value.some((n: any) => n.id === r.to),
    ) || [],
);
const recallRelations = computed(
  () =>
    content.value?.relations.filter(
      (r: any) =>
        chapter.value === "all" ||
        nodes.value.some(
          (n: any) =>
            n.module === chapter.value && (n.id === r.from || n.id === r.to),
        ),
    ) || [],
);
const comparison = computed(() => comparisons.value[ci.value]),
  exercise = computed(() => exercises.value[qi.value]),
  recallNodes = computed(() => recallSet(filtered.value, recallIndex.value, 3)),
  recallRelation = computed(
    () =>
      recallRelations.value[
        recallIndex.value % (recallRelations.value.length || 1)
      ],
  );
const due = computed(() =>
  nodes.value.filter(
    (n: any) => state.value?.nodes[n.id]?.mastery !== "mastered",
  ),
);
const scheduledDue = computed(() =>
  latestDue(state.value?.recalls || [], Date.now()),
);
function title(id: string) {
  return nodes.value.find((n: any) => n.id === id)?.title || id;
}
/** 唯一的后端调用:GET /api/v2/content 拉只读题库。 */
async function api(path: string) {
  const r = await fetch("/api/v2" + path, { credentials: "same-origin" });
  const json = await r.json().catch(() => null);
  if (!r.ok)
    throw Object.assign(Error(json?.error?.message || "请求失败"), {
      status: r.status,
    });
  return json?.data;
}
/** 题库来自 /content;学习状态全部来自 localStorage(无则 blank)。 */
async function load() {
  loading.value = true;
  error.value = "";
  try {
    const c = await api("/content");
    content.value = c;
    state.value = readLocalState();
    if (state.value?.resume?.view)
      view.value =
        ({ overview: "home", lab: "labs" } as any)[state.value.resume.view] ||
        state.value.resume.view;
  } catch (e: any) {
    // 题库未加载且无缓存时提示;state 仍可先用本地数据。
    error.value = e.message || "内容载入失败";
    if (!state.value) state.value = readLocalState();
  } finally {
    loading.value = false;
  }
}
async function run(fn: () => Promise<any>) {
  busy.value = true;
  error.value = "";
  try {
    await fn();
  } catch (e: any) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
/** 学习事件纯本地应用,语义同原后端 Application::apply();不发网络请求。 */
function event(type: string, payload: any) {
  if (!state.value || !content.value) return;
  const s = state.value;
  const now = Date.now();
  const nodeId = payload?.nodeId ?? null;
  if (type !== "resume" || nodeId !== null) {
    if (
      !nodeId ||
      !content.value.nodes.some((n: any) => n.id === nodeId)
    )
      return;
  }
  const entry = nodeId ? s.nodes[nodeId] || blankNode() : null;
  switch (type) {
    case "visit":
      entry.visited = true;
      entry.updatedAt = now;
      s.nodes[nodeId] = entry;
      break;
    case "mastery": {
      if (!["unlearned", "fuzzy", "mastered"].includes(payload.mastery)) return;
      entry.mastery = payload.mastery;
      entry.masteryUpdatedAt = now;
      entry.updatedAt = now;
      s.nodes[nodeId] = entry;
      break;
    }
    case "recall": {
      if (!["again", "hard", "good"].includes(payload.rating)) return;
      const intervals: Record<string, number> = {
        again: 600000,
        hard: 86400000,
        good: 259200000,
      };
      s.recalls.push({
        id: crypto.randomUUID(),
        nodeId,
        rating: payload.rating,
        mode: String(payload.mode || "concept"),
        at: now,
        dueAt: now + intervals[payload.rating],
      });
      break;
    }
    case "resume": {
      const mapped = payload.view;
      if (
        !["map", "stars", "compare", "lab", "recall", "quiz", "notebook", "overview"].includes(
          mapped,
        )
      )
        return;
      s.resume = { view: mapped, nodeId };
      break;
    }
    default:
      return;
  }
  persistState();
}
function navigate(v: string) {
  v = ({ overview: "home", lab: "labs" } as any)[v] || v;
  view.value = v;
  if (!["map", "stars"].includes(v)) query.value = "";
  event("resume", {
    view: ({ home: "overview", labs: "lab" } as any)[v] || v,
    nodeId: selected.value?.id || null,
  });
}
function chooseChapter(id: string) {
  chapter.value = id;
  selected.value = null;
  query.value = "";
}
function openNode(n: any) {
  if (!n) return;
  selected.value = n;
  event("visit", { nodeId: n.id });
}
/** 练习提交:本地判分并记录 attempt,语义同原后端 /attempts;不发网络请求。 */
async function submit() {
  if (choice.value < 0 || !exercise.value || !state.value) return;
  const intent = attemptIntent(
    pendingAttempt.value,
    {
      exerciseId: exercise.value.id,
      chosen: choice.value,
      reason: reason.value,
    },
    crypto.randomUUID(),
  );
  await run(async () => {
    const q = exercise.value;
    const correct = intent.chosen === q.answer;
    const now = Date.now();
    const s = state.value;
    // 同一 intent 重复提交(重试按钮)幂等:已记录同 id 则直接复用结果。
    if (!s.attempts.some((a: any) => a.id === intent.eventId)) {
      s.attempts.push({
        id: intent.eventId,
        exerciseId: q.id,
        chosen: intent.chosen,
        reason: intent.reason,
        correct,
        answer: q.answer,
        explanation: q.explanation,
        contentVersion: content.value?.contentVersion,
        nodes: q.nodes,
        at: now,
        verified: true,
      });
      for (const n of q.nodes || []) {
        const entry = s.nodes[n] || blankNode();
        entry.visited = true;
        entry.updatedAt = now;
        s.nodes[n] = entry;
      }
      persistState(true);
    }
    answer.value = { correct, answer: q.answer, explanation: q.explanation };
    pendingAttempt.value = null;
  });
}
function nextQuiz(delta: number) {
  pendingAttempt.value = null;
  qi.value =
    (qi.value + delta + exercises.value.length) % exercises.value.length;
  choice.value = -1;
  answer.value = null;
  reason.value = "";
}
function nextRecall() {
  recallIndex.value =
    (recallIndex.value + (recallMode.value === "relationship" ? 1 : 3)) %
    Math.max(
      1,
      recallMode.value === "relationship"
        ? recallRelations.value.length
        : filtered.value.length,
    );
  recallReveal.value = false;
  recallText.value = "";
}
function rate(rating: string) {
  const mode = recallMode.value;
  const ids =
    mode === "relationship"
      ? [recallRelation.value?.from, recallRelation.value?.to].filter(Boolean)
      : mode === "case"
        ? [recallNodes.value[0]?.id].filter(Boolean)
        : recallNodes.value.map((n: any) => n.id);
  for (const nodeId of ids) event("recall", { nodeId, rating, mode });
  nextRecall();
}
/** 导出 localStorage 中的学习记录为 JSON 备份(纯前端,不调 /export)。 */
function exportData() {
  if (!state.value) return;
  const data = {
    version: 2,
    exportedAt: Date.now(),
    contentVersion: content.value?.contentVersion,
    state: state.value,
  };
  const url = URL.createObjectURL(
    new Blob([JSON.stringify(data, null, 2)], { type: "application/json" }),
  );
  const a = document.createElement("a");
  a.href = url;
  a.download = "马原学习备份.json";
  a.click();
  URL.revokeObjectURL(url);
}
async function readImport(e: Event) {
  await run(async () => {
    const f = (e.target as HTMLInputElement).files?.[0];
    if (!f) return;
    if (f.size > 5000000) throw Error("文件不能超过 5 MB");
    const raw = JSON.parse(await f.text());
    const preview = previewImport(raw);
    importRaw.value = raw;
    importPreview.value = preview;
  });
}
/** 合并备份到本地 state(语义同原后端 /import:节点取并集,记录去重,答题仅入历史未验证)。 */
async function confirmImport() {
  await run(async () => {
    const s = state.value;
    if (!s || !importRaw.value) return;
    const p = importRaw.value.progress || importRaw.value.state || importRaw.value;
    const now = Date.now();
    const known = new Set((content.value?.nodes || []).map((n: any) => n.id));
    if (p.nodes && typeof p.nodes === "object" && !Array.isArray(p.nodes)) {
      for (const [id, node] of Object.entries<any>(p.nodes)) {
        if (!known.has(id)) continue;
        const existing = s.nodes[id] || blankNode();
        existing.visited = existing.visited || !!node.visited;
        const mu = node.masteryUpdatedAt || 0;
        if (
          mu > existing.masteryUpdatedAt ||
          (mu === existing.masteryUpdatedAt &&
            existing.mastery === "unlearned" &&
            node.mastery &&
            node.mastery !== "unlearned")
        ) {
          existing.mastery = node.mastery;
          existing.masteryUpdatedAt = mu;
        }
        existing.updatedAt = now;
        s.nodes[id] = existing;
      }
    }
    for (const r of p.recalls || []) {
      if (r?.id && known.has(r.nodeId) && !s.recalls.some((x: any) => x.id === r.id))
        s.recalls.push(r);
    }
    for (const a of [...(p.historicalAnswers || []), ...(p.attempts || [])]) {
      if (!a?.id || typeof a.correct !== "boolean") continue;
      if (s.historicalAnswers.some((x: any) => x.id === a.id)) continue;
      s.historicalAnswers.push({ ...a, verified: false, source: "import" });
    }
    importRaw.value = null;
    importPreview.value = null;
    persistState(true);
    notice.value = "备份已合并，历史练习仅作为未验证记录保留。";
  });
}
onMounted(load);
let previousFocus: HTMLElement | null = null;
watch(
  () => relation.value?.id || selected.value?.id,
  async (open) => {
    if (open) {
      previousFocus = document.activeElement as HTMLElement;
      await nextTick();
      (
        document.querySelector(".modal .close") ||
        (document.querySelector(".drawer .close") as HTMLElement)
      )?.focus();
    } else previousFocus?.focus();
  },
);
function trapDialog(e: KeyboardEvent) {
  if (!selected.value && !relation.value) return;
  const dialog = document.querySelector(
    relation.value ? ".modal" : ".drawer",
  ) as HTMLElement;
  if (e.key === "Escape") {
    if (relation.value) relation.value = null;
    else selected.value = null;
    e.preventDefault();
    return;
  }
  if (e.key === "Tab") {
    const controls = Array.from(
      dialog.querySelectorAll<HTMLElement>(
        'button:not(:disabled),input,textarea,select,[tabindex="0"]',
      ),
    );
    const first = controls[0],
      last = controls.at(-1);
    if (e.shiftKey && document.activeElement === first) {
      last?.focus();
      e.preventDefault();
    } else if (!e.shiftKey && document.activeElement === last) {
      first?.focus();
      e.preventDefault();
    }
  }
}
onMounted(() => document.addEventListener("keydown", trapDialog));
onUnmounted(() => document.removeEventListener("keydown", trapDialog));
</script>

<template>
  <div class="shell">
    <aside class="sidebar">
      <a class="brand" href="#main" @click.prevent="navigate('home')"
        ><span class="brand-icon">✳</span
        ><span>马原知识宇宙<small>KNOWLEDGE, CONNECTED.</small></span></a
      >
      <p class="nav-label">我的学习空间</p>
      <nav>
        <button
          v-for="([id, label], i) in views"
          :key="id"
          :class="{ active: view === id }"
          @click="navigate(id)"
        >
          <span class="nav-number">0{{ i + 1 }}</span
          >{{ label }}</button
        >
      </nav>
      <div class="sidebar-foot">
        <span class="dot"></span> 独立学习平台<small
          >本地学习模式 · 记录仅保存在此浏览器<br />学习无解锁门槛 · 不设排名</small
        >
      </div>
    </aside>
    <div class="workspace">
      <header>
        <div class="breadcrumb">
          学习空间 <span>/</span>
          {{ views.find((v) => v[0] === view)?.[1] }}
        </div>
      </header>
      <main id="main">
        <div v-if="loading" class="panel">正在读取内容与本地学习记录…</div>
        <template v-else-if="content"
          ><div v-if="error" role="alert" class="alert">
            {{ error }} <button @click="load">重新读取</button>
          </div>
          <div v-if="notice" role="status" class="notice">
            {{ notice
            }}<button aria-label="关闭提示" @click="notice = ''">×</button>
          </div>
          <section v-if="view === 'home'">
            <div class="page-heading">
              <div>
                <p class="eyebrow">YOUR LEARNING WORKSPACE</p>
                <h1>把知识连起来，<br /><em>让理解更进一步。</em></h1>
                <p>从一个概念出发，发现原理之间的联系。</p>
              </div>
              <div class="date-card">
                <span>学习不止于记忆</span><strong>理解 → 回忆 → 应用</strong
                ><small
                  >{{ nodes.length }} 个概念 ·
                  {{ content.relations.length }} 条联系</small
                >
              </div>
            </div>
            <div class="stats">
              <div>
                <small>已探索的概念</small
                ><strong
                  >{{ state?.summary?.visited || 0
                  }}<span>/ {{ nodes.length }}</span></strong
                >
              </div>
              <div>
                <small>自主评估 · 已掌握</small
                ><strong>{{ state?.summary?.mastered || 0 }}</strong>
              </div>
              <div>
                <small>原创练习正确</small
                ><strong
                  >{{ state?.summary?.correct || 0
                  }}<span>/ {{ state?.summary?.attempts || 0 }}</span></strong
                >
              </div>
              <div>
                <small>已到期回忆</small
                ><strong>{{ scheduledDue.length }}</strong>
              </div>
            </div>
            <div class="home-grid">
              <article class="feature-card">
                <p class="eyebrow">TODAY'S FOCUS</p>
                <h2>从理解到表达</h2>
                <p>
                  选择一个仍模糊的概念，先回忆，再验证。<br />自评和练习成绩分别记录。
                </p>
                <button class="primary" @click="navigate('recall')">
                  开始今日回忆 ↗
                </button>
              </article>
              <article class="panel">
                <p class="eyebrow">PICK UP WHERE YOU LEFT OFF</p>
                <h2>继续上次学习</h2>
                <p>
                  {{
                    state?.resume?.nodeId
                      ? title(state.resume.nodeId)
                      : "还没有学习记录，从知识地图开始探索。"
                  }}
                </p>
                <button
                  @click="
                    navigate(state?.resume?.view || 'map');
                    state?.resume?.nodeId &&
                      openNode(
                        nodes.find((n: any) => n.id === state.resume.nodeId),
                      );
                  "
                >
                  继续学习 →
                </button>
              </article>
            </div>
            <div class="section-title">
              <h2>七个章节，一个知识网络</h2>
              <span>全部开放</span>
            </div>
            <div class="chapters">
              <button
                v-for="(m, i) in content.modules"
                @click="
                  chapter = m.id;
                  selected = null;
                  navigate('map');
                "
              >
                <span class="chapter-index">0{{ i + 1 }}</span>
                <h3>{{ m.title }}</h3>
                <p>{{ m.subtitle }}</p>
                <small
                  >{{
                    nodes.filter((n: any) => n.module === m.id).length
                  }}
                  个概念 →</small
                >
              </button>
            </div>
            <p class="fineprint">
              内容来自经辨析的个人笔记与课程材料，非完整考研教材；30
              道练习均为原创，非历年真题。
            </p>
          </section>
          <section v-if="['map', 'stars'].includes(view)">
            <div class="section-title">
              <div>
                <p class="eyebrow">CONNECTED KNOWLEDGE</p>
                <h1>{{ view === "stars" ? "知识星空" : "知识地图" }}</h1>
                <p>点击概念阅读，点击联系查看条件与易错点。</p>
              </div>
              <button @click="view = view === 'map' ? 'stars' : 'map'">
                {{ view === "map" ? "切换星空" : "切换地图" }}
              </button>
            </div>
            <div class="toolbar">
              <input
                v-model="query"
                @input="selected = null"
                aria-label="搜索概念"
                placeholder="搜索概念、原理或易错点…"
              /><select
                v-model="chapter"
                aria-label="选择章节"
                @change="selected = null"
              >
                <option value="all">全部章节</option>
                <option v-for="m in content.modules" :value="m.id">
                  {{ m.title }}
                </option></select
              ><button v-if="selected" @click="selected = null">
                退出概念聚焦
              </button>
            </div>
            <p class="fineprint">
              {{ filtered.length }} 个匹配概念 · 全局概览 7
              个章节，选择章节查看概念；聚焦概念可查看跨章节联系。
            </p>
            <template v-if="view === 'stars'">
              <p v-if="webglFailed" class="fineprint" role="status">
                当前环境不支持 3D 渲染，已切换为简化星空视图。
              </p>
              <ClientOnly v-if="!webglFailed && !query && content && content.nodes">
                <!-- Lazy 前缀 → three.js 分包仅在进入星空视图时加载 -->
                <LazyUniverseScene
                  :nodes="content.nodes"
                  :relations="content.relations"
                  :modules="content.modules"
                  :mastery="state?.nodes || {}"
                  :selected-id="selected?.id"
                  :chapter="chapter"
                  @open="openNode"
                  @relation="relation = $event"
                  @chapter="chooseChapter"
                  @webgl-fail="webglFailed = true"
                />
                <template #fallback>
                  <p class="fineprint" role="status">正在加载 3D 星空…（首次加载 Three.js 分包）</p>
                </template>
              </ClientOnly>
              <KnowledgeGraph
                v-else
                :nodes="positions"
                :relations="edges"
                :all-nodes="content.nodes"
                :modules="content.modules"
                :all-relations="content.relations"
                :overview="chapter === 'all' && !query && !selected"
                @chapter="chooseChapter"
                :selected-id="selected?.id"
                :mastery="state?.nodes || {}"
                :star="true"
                @open="openNode"
                @relation="relation = $event"
              />
            </template>
            <KnowledgeGraph
              v-else
              :nodes="positions"
              :relations="edges"
              :all-nodes="content.nodes"
              :modules="content.modules"
              :all-relations="content.relations"
              :overview="chapter === 'all' && !query && !selected"
              @chapter="chooseChapter"
              :selected-id="selected?.id"
              :mastery="state?.nodes || {}"
              :star="false"
              @open="openNode"
              @relation="relation = $event"
            />
            <p v-if="!filtered.length" class="panel">
              没有找到匹配概念，试试更短的关键词。
            </p>
            <!-- 3D 星空的 HTML 等价入口：概念列表兜底，键盘可达 -->
            <div v-if="view === 'stars' && !webglFailed && !query" class="concept-list stars-fallback-list">
              <template v-if="chapter === 'all' && !selected">
                <button v-for="m in content.modules" :key="m.id" @click="chooseChapter(m.id)">
                  <strong>{{ m.title }}</strong>
                  <span>{{ nodes.filter((n: any) => n.module === m.id).length }} 个概念 · 进入星系 →</span>
                </button>
              </template>
              <template v-else>
                <button v-for="n in filtered" :key="n.id" @click="openNode(n)">
                  <strong>{{ n.title }}</strong>
                  <span>{{ state?.nodes?.[n.id]?.mastery === 'mastered' ? '自评已掌握' : '阅读概念' }} →</span>
                </button>
              </template>
            </div>
            <div class="relation-list">
              <button
                v-for="r in content.relations.filter((r: any) =>
                  selected
                    ? r.from === selected.id || r.to === selected.id
                    : chapter === 'all'
                      ? false
                      : nodes.find((n: any) => n.id === r.from)?.module ===
                        chapter,
                )"
                @click="relation = r"
              >
                {{ title(r.from) }} <span>{{ r.label }}</span>
                {{ title(r.to) }} ↗
              </button>
            </div>
          </section>
          <section v-if="view === 'compare'">
            <p class="eyebrow">DISTINGUISH & UNDERSTAND</p>
            <h1>概念辨析</h1>
            <p>
              先写出区别，再揭示辨析依据。{{ comparisons.length }}
              组概念均可自由选择。
            </p>
            <select
              v-model.number="ci"
              @change="
                revealed = false;
                judgment = '';
              "
              aria-label="选择辨析"
            >
              <option v-for="(c, i) in comparisons" :value="i">
                {{ c.title }}
              </option>
            </select>
            <article v-if="comparison" class="panel study-card">
              <h2>{{ comparison.title }}</h2>
              <p>这两个概念能互相替代吗？它们分别回答什么问题？</p>
              <textarea
                v-model="judgment"
                placeholder="先写下你的判断与依据"
                aria-label="辨析判断"
              ></textarea
              ><button class="primary" @click="revealed = true">
                揭示辨析依据
              </button>
              <table v-if="revealed">
                <thead>
                  <tr>
                    <th>辨析维度</th>
                    <th>{{ comparison.leftLabel }}</th>
                    <th>{{ comparison.rightLabel }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in comparison.rows">
                    <th>{{ r.label }}</th>
                    <td>{{ r.left }}</td>
                    <td>{{ r.right }}</td>
                  </tr>
                </tbody>
              </table>
              <div class="row">
                <button
                  @click="
                    openNode(nodes.find((n: any) => n.id === comparison.left))
                  "
                >
                  阅读 {{ comparison.leftLabel }}</button
                ><button
                  @click="
                    openNode(nodes.find((n: any) => n.id === comparison.right))
                  "
                >
                  阅读 {{ comparison.rightLabel }}
                </button>
              </div>
            </article>
            <CorrectionPractice />
          </section>
          <template v-if="view === 'labs'"
            ><PrincipleLabs /><RelationBuilder
              :nodes="nodes"
              :relations="content.relations"
          /></template>
          <section v-if="view === 'recall'">
            <p class="eyebrow">RETRIEVE, THEN CHECK</p>
            <h1>主动回忆</h1>
            <div class="toolbar">
              <select
                v-model="recallMode"
                @change="
                  recallReveal = false;
                  recallText = '';
                "
                aria-label="回忆模式"
              >
                <option value="concept">概念遮盖</option>
                <option value="relationship">关系回忆</option>
                <option value="framework">框架重建</option>
                <option value="case">案例自评</option></select
              ><select
                v-model="chapter"
                aria-label="回忆章节"
                @change="
                  recallIndex = 0;
                  recallReveal = false;
                "
              >
                <option value="all">全部章节</option>
                <option v-for="m in content.modules" :value="m.id">
                  {{ m.title }}
                </option></select
              ><button @click="nextRecall">换一组</button>
            </div>
            <article class="panel study-card">
              <template v-if="recallMode === 'concept'"
                ><h2>根据线索回忆概念</h2>
                <p v-for="(n, i) in recallNodes">
                  {{ i + 1 }}. {{ maskConcept(n) }}
                </p>
                <div v-if="recallReveal">
                  <p v-for="n in recallNodes">
                    <b>{{ n.title }}</b
                    >：{{ n.summary }}
                  </p>
                </div></template
              ><template v-if="recallMode === 'relationship'"
                ><h2>
                  {{ title(recallRelation?.from) }} 与
                  {{ title(recallRelation?.to) }} 有什么联系？
                </h2>
                <p>说明联系成立的条件，并指出一个容易误解的说法。</p>
                <div v-if="recallReveal">
                  <p>
                    {{ recallRelation?.label }}：{{
                      recallRelation?.explanation
                    }}
                  </p>
                  <p>条件：{{ recallRelation?.condition }}</p>
                  <p>易错：{{ recallRelation?.trap }}</p>
                </div></template
              ><template v-if="recallMode === 'framework'"
                ><h2>用这些概念重建一个框架</h2>
                <div class="chips">
                  <span v-for="n in recallNodes">{{ n.title }}</span>
                </div>
                <p>
                  分别写出定义、方法与联系。如果没有直接关系，说明各自位置，避免强行连线。
                </p>
                <div v-if="recallReveal">
                  <p v-for="n in recallNodes">
                    <b>{{ n.title }}</b
                    >：{{ n.summary }} {{ n.method }}
                  </p>
                </div></template
              ><template v-if="recallMode === 'case'"
                ><h2>用原理分析一个情境</h2>
                <p>{{ recallNodes[0]?.example }}</p>
                <p>可以用什么原理解释？适用条件是什么？</p>
                <p v-if="recallReveal">
                  参考自评：{{ recallNodes[0]?.title }}。{{
                    recallNodes[0]?.method
                  }}
                  注意：{{ recallNodes[0]?.trap }}
                </p></template
              ><textarea
                v-model="recallText"
                placeholder="不看答案，先写下你的回忆"
                aria-label="回忆内容"
              ></textarea
              ><button class="primary" @click="recallReveal = true">
                查看参考依据
              </button>
              <div v-if="recallReveal" class="row">
                <span>自评，不作为客观成绩：</span
                ><button @click="rate('again')">需要重学</button
                ><button @click="rate('hard')">仍有困难</button
                ><button @click="rate('good')">能够解释</button>
              </div>
            </article>
          </section>
          <section v-if="view === 'quiz'">
            <p class="eyebrow">APPLY YOUR KNOWLEDGE</p>
            <h1>原创练习</h1>
            <p>
              {{ exercises.length }} 道原创单选题 · 非历年真题 ·
              答案由服务端核验
            </p>
            <article v-if="exercise" class="panel study-card">
              <div class="section-title">
                <span>{{ qi + 1 }} / {{ exercises.length }}</span>
                <div>
                  <button @click="nextQuiz(-1)">上一题</button
                  ><button @click="nextQuiz(1)">下一题</button>
                </div>
              </div>
              <h2>{{ exercise.prompt }}</h2>
              <div class="options">
                <label
                  v-for="(o, i) in exercise.options"
                  :class="{ chosen: choice === i }"
                  ><input
                    type="radio"
                    :value="i"
                    v-model="choice"
                    :disabled="!!answer || !!pendingAttempt"
                  />{{ String.fromCharCode(65 + i) }}. {{ o }}</label
                >
              </div>
              <textarea
                v-model="reason"
                :disabled="!!pendingAttempt"
                aria-label="选择依据"
                placeholder="写下你的选择依据，方便复盘"
              ></textarea
              ><button
                class="primary"
                :disabled="choice < 0 || busy || !!answer"
                @click="submit"
              >
                {{ pendingAttempt ? "重试同一次提交" : "提交并核验" }}
              </button>
              <div v-if="answer" class="answer">
                <h3>
                  {{ answer.correct ? "回答正确" : "还需要辨析" }} · 正确选项
                  {{ String.fromCharCode(65 + answer.answer) }}
                </h3>
                <p>{{ answer.explanation }}</p>
                <p>你的依据：{{ reason || "未填写" }}</p>
              </div>
              <div class="chips">
                <button
                  v-for="id in exercise.nodes"
                  @click="openNode(nodes.find((n: any) => n.id === id))"
                >
                  {{ title(id) }}
                </button>
              </div>
            </article>
          </section>
          <section v-if="view === 'notebook'">
            <p class="eyebrow">YOUR PERSONAL COLLECTION</p>
            <h1>复习记录</h1>
            <div class="row">
              <button @click="exportData">
                下载 JSON 备份</button
              ><label class="file-button"
                >导入备份预览<input
                  type="file"
                  accept="application/json,.json"
                  @change="readImport" /></label
              >
            </div>
            <p class="fineprint">
              学习记录仅保存在当前浏览器的 localStorage；清除浏览器数据会重置，可在换设备前导出备份再导入。
            </p>
            <div v-if="importPreview" class="panel">
              <h3>导入预览</h3>
              <p>
                {{ importPreview.nodes }} 个知识点 ·
                {{ importPreview.attempts }} 条练习。导入会合并到本地记录；历史答题标记为未验证。
              </p>
              <button class="primary" @click="confirmImport">
                确认合并到本地记录</button
              ><button
                @click="
                  importRaw = null;
                  importPreview = null;
                "
              >
                取消
              </button>
            </div>
            <h2>已到期回忆 · {{ scheduledDue.length }}</h2>
            <div class="chips">
              <button
                v-for="r in scheduledDue"
                @click="openNode(nodes.find((n: any) => n.id === r.nodeId))"
              >
                {{ title(r.nodeId) }}
              </button>
            </div>
            <p v-if="!scheduledDue.length" class="fineprint">
              当前没有到期回忆。完成主动回忆后，会根据自评安排下次回忆。
            </p>
            <h2>尚未自评掌握的概念</h2>
            <div class="chips">
              <button v-for="n in due.slice(0, 20)" @click="openNode(n)">
                {{ n.title }}
              </button>
            </div>
          </section>
        </template>
        <div v-if="!loading && !content" role="alert" class="panel">
          {{ error || "内容尚未载入" }}<button @click="load">重新读取</button>
        </div>
      </main>
      <footer>马原知识宇宙 V2 <span>以理解为起点，以联系为路径。</span></footer>
    </div>
    <div v-if="selected" class="drawer-backdrop" @click.self="selected = null">
      <aside
        class="drawer"
        role="dialog"
        aria-modal="true"
        :aria-label="selected.title"
        @keydown.esc="selected = null"
      >
        <button
          class="close"
          @click="selected = null"
          aria-label="关闭概念详情"
        >
          ×
        </button>
        <p class="eyebrow">CONCEPT NOTE</p>
        <h2>{{ selected.title }}</h2>
        <p class="summary">{{ selected.summary }}</p>
        <h3>理解原理</h3>
        <p>{{ selected.detail }}</p>
        <h3>怎么运用</h3>
        <p>{{ selected.method }}</p>
        <p class="boundary">易错点：{{ selected.trap }}</p>
        <p>例子：{{ selected.example }}</p>
        <h3>自主掌握程度</h3>
        <p class="fineprint">自评独立于浏览与答题成绩。</p>
        <div class="row">
          <button
            v-for="[m, label] in [
              ['unlearned', '未学习'],
              ['fuzzy', '仍模糊'],
              ['mastered', '已掌握'],
            ]"
            :class="{ active: state?.nodes[selected.id]?.mastery === m }"
            @click="event('mastery', { nodeId: selected.id, mastery: m })"
          >
            {{ label }}
          </button>
        </div>
        <h3>相关联系</h3>
        <button
          class="relation-button"
          v-for="r in content.relations.filter(
            (r: any) => r.from === selected.id || r.to === selected.id,
          )"
          @click="relation = r"
        >
          {{ title(r.from) }} · {{ r.label }} · {{ title(r.to) }}
        </button>
        <h3>来源与核验说明</h3>
        <p class="fineprint" v-for="s in selected.sources">
          {{ s.label }}<br />{{ s.locator }}
        </p>
      </aside>
    </div>
    <div v-if="relation" class="modal-backdrop" @click.self="relation = null">
      <article
        class="modal panel"
        role="dialog"
        aria-modal="true"
        aria-label="关系说明"
        @keydown.esc="relation = null"
      >
        <button class="close" @click="relation = null" aria-label="关闭关系">
          ×
        </button>
        <p class="eyebrow">RELATION & CONDITIONS</p>
        <h2>{{ title(relation.from) }} → {{ title(relation.to) }}</h2>
        <span class="tag">{{ relation.kind }} · {{ relation.label }}</span>
        <p>{{ relation.explanation }}</p>
        <h3>成立条件</h3>
        <p>{{ relation.condition }}</p>
        <h3>易错辨析</h3>
        <p>{{ relation.trap }}</p>
        <div class="row">
          <button
            @click="
              openNode(nodes.find((n: any) => n.id === relation.from));
              relation = null;
            "
          >
            查看起点</button
          ><button
            @click="
              openNode(nodes.find((n: any) => n.id === relation.to));
              relation = null;
            "
          >
            查看终点
          </button>
        </div>
      </article>
    </div>
  </div>
</template>

<style>
:root {
  --bg: #f6f5f0;
  --paper: #fffefb;
  --ink: #233c38;
  --muted: #74817b;
  --teal: #176c5b;
  --line: #e2e6dd;
  --soft: #eaf0e8;
}
* {
  box-sizing: border-box;
}
body {
  margin: 0;
  background: var(--bg);
  color: var(--ink);
  font-family: Inter, "Microsoft YaHei", sans-serif;
  -webkit-font-smoothing: antialiased;
  font-size: 14px;
  line-height: 1.75;
}
button,
input,
select,
textarea {
  font: inherit;
}
button,
a,
select,
label.file-button {
  touch-action: manipulation;
}
button {
  min-height: 44px;
  padding: 9px 17px;
  border: 1px solid var(--line);
  background: var(--paper);
  color: var(--ink);
  border-radius: 9px;
  cursor: pointer;
  transition:
    background 0.15s,
    color 0.15s,
    transform 0.15s;
}
button:hover {
  background: var(--soft);
}
button:active {
  transform: scale(0.96);
}
button:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
svg g:focus-visible {
  outline: 3px solid #dbac52;
  outline-offset: 3px;
}
h1,
h2,
h3 {
  font-family: "Microsoft YaHei", sans-serif;
  line-height: 1.45;
  text-wrap: balance;
}
h1 {
  font-size: 36px;
  letter-spacing: -1.3px;
  margin: 8px 0 18px;
}
h2 {
  font-size: 21px;
  margin: 10px 0 16px;
}
h3 {
  font-size: 16px;
}
p {
  text-wrap: pretty;
}
em {
  font-style: normal;
  color: var(--teal);
}
input,
select,
textarea {
  border: 1px solid var(--line);
  background: var(--paper);
  color: var(--ink);
  padding: 11px 14px;
  border-radius: 8px;
  min-height: 44px;
}
textarea {
  display: block;
  width: 100%;
  min-height: 110px;
  resize: vertical;
  margin: 18px 0;
}
input[type="range"] {
  width: 100%;
  accent-color: var(--teal);
}
.shell {
  display: flex;
  min-height: 100vh;
}
.sidebar {
  width: 238px;
  padding: 35px 23px;
  border-right: 1px solid var(--line);
  position: fixed;
  height: 100vh;
  display: flex;
  flex-direction: column;
  background: #fafaf5;
}
.brand {
  display: flex;
  gap: 11px;
  align-items: center;
  color: var(--ink);
  text-decoration: none;
  font-size: 17px;
  font-weight: 700;
  line-height: 1.5;
}
.brand-icon {
  color: var(--teal);
  font-size: 34px;
}
.brand small {
  display: block;
  font-size: 8px;
  letter-spacing: 1.4px;
  color: var(--muted);
  margin-top: 5px;
}
.nav-label {
  font-size: 11px;
  color: var(--muted);
  margin: 44px 11px 14px;
  letter-spacing: 2px;
}
.sidebar nav {
  display: grid;
  gap: 7px;
}
.sidebar nav button {
  border: 0;
  background: none;
  text-align: left;
  padding: 10px 13px;
  font-size: 13px;
}
.sidebar nav button.active {
  background: #e6eee4;
  color: var(--teal);
  font-weight: 700;
}
.nav-number {
  font-size: 10px;
  letter-spacing: 1px;
  color: #91a397;
  margin-right: 13px;
}
.sidebar-foot {
  margin-top: auto;
  font-size: 12px;
  color: var(--muted);
}
.sidebar-foot small {
  display: block;
  line-height: 1.9;
  margin-top: 12px;
  font-size: 10px;
}
.dot {
  display: inline-block;
  background: #2e9175;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  margin-right: 7px;
}
.workspace {
  margin-left: 238px;
  width: calc(100% - 238px);
}
header {
  height: 77px;
  border-bottom: 1px solid var(--line);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 42px;
  font-size: 12px;
}
.breadcrumb {
  color: var(--muted);
}
.breadcrumb span {
  margin: 0 15px;
  color: #b5c0b6;
}
.account {
  display: flex;
  align-items: center;
  gap: 12px;
}
.account button {
  min-height: 32px;
  padding: 3px 12px;
  font-size: 11px;
}
.dev {
  font-size: 10px;
  border-radius: 20px;
  padding: 3px 8px;
  background: #f2ead5;
  color: #836e35;
}
main {
  padding: 40px 42px 60px;
  max-width: 1400px;
  margin: auto;
}
.page-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 30px;
  margin: 7px 0 33px;
}
.page-heading h1 {
  font-size: 40px;
  line-height: 1.55;
}
.page-heading p {
  color: var(--muted);
}
.eyebrow {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 2.6px;
  color: var(--teal);
  margin: 0 0 12px;
}
.date-card {
  border-left: 1px solid #cbd6c8;
  padding: 18px 0 18px 35px;
  display: grid;
  gap: 13px;
  font-size: 12px;
}
.date-card span,
.date-card small {
  color: var(--muted);
}
.date-card strong {
  font-size: 16px;
}
.stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  border: 1px solid var(--line);
  border-radius: 13px;
  background: var(--paper);
  padding: 25px 0;
  margin: 20px 0 29px;
}
.stats > div {
  padding: 0 27px;
  border-right: 1px solid var(--line);
}
.stats > div:last-child {
  border: 0;
}
.stats small {
  display: block;
  color: var(--muted);
  font-size: 11px;
}
.stats strong {
  display: block;
  font-size: 32px;
  font-weight: 500;
  line-height: 1.5;
  font-variant-numeric: tabular-nums;
}
.stats strong span {
  font-size: 13px;
  color: #a1aea2;
  margin-left: 8px;
}
.home-grid {
  display: grid;
  grid-template-columns: 1.13fr 1fr;
  gap: 20px;
}
.feature-card {
  background: #e3ece2;
  border-radius: 13px;
  padding: 29px 31px;
}
.feature-card p:not(.eyebrow) {
  color: #718471;
  font-size: 12px;
}
.panel {
  background: var(--paper);
  border: 1px solid var(--line);
  border-radius: 13px;
  padding: 28px;
}
.primary {
  background: var(--teal);
  border-color: var(--teal);
  color: white;
}
.primary:hover {
  background: #0e5245;
}
.section-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin: 35px 0 18px;
}
.section-title span {
  font-size: 11px;
  color: var(--muted);
}
.chapters {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
}
.chapters button {
  text-align: left;
  padding: 21px;
  background: var(--paper);
  min-height: 164px;
}
.chapter-index {
  font-size: 11px;
  color: #879a80;
  letter-spacing: 2px;
}
.chapters h3 {
  margin: 12px 0 7px;
  font-size: 15px;
}
.chapters p {
  font-size: 11px;
  color: var(--muted);
  margin: 0 0 12px;
}
.chapters small {
  font-size: 10px;
  color: var(--teal);
}
.fineprint {
  font-size: 11px;
  color: var(--muted);
  line-height: 1.9;
}
.fineprint:last-child {
  margin-top: 22px;
}
.toolbar,
.row,
.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin: 20px 0;
}
.toolbar input {
  flex: 1;
  min-width: 180px;
}
.graph {
  background: #eef2e9;
  border: 1px solid var(--line);
  border-radius: 14px;
  overflow: auto;
  min-height: 330px;
}
.graph svg {
  display: block;
  width: 100%;
  min-width: 700px;
}
.edge {
  cursor: pointer;
}
.edge line {
  stroke: #acc7b6;
  stroke-width: 2;
}
.edge text {
  font-size: 9px;
  fill: #728f7c;
  text-anchor: middle;
  paint-order: stroke;
  stroke: #eef2e9;
  stroke-width: 4px;
  stroke-linejoin: round;
}
.graph-node {
  cursor: pointer;
}
.graph-node rect {
  fill: var(--paper);
  stroke: #c0d3c3;
  stroke-width: 1.5;
}
.graph-node text {
  text-anchor: middle;
  font-size: 11px;
  fill: var(--ink);
}
.graph-node.selected rect,
.graph-node.mastered rect {
  stroke: #176c5b;
  stroke-width: 3;
}
.star {
  background: #103e37;
  background-image: radial-gradient(#6b96833b 1px, transparent 1px);
  background-size: 29px 29px;
}
.star .edge line {
  stroke: #386d5a;
}
.star .edge text {
  fill: #b1cdbc;
  stroke: #103e37;
}
.star .graph-node rect {
  fill: #1c5144;
  stroke: #729784;
}
.star .graph-node text {
  fill: #ecf4df;
}
.relation-list {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 20px;
}
.relation-list button {
  font-size: 11px;
}
.relation-list span {
  color: var(--teal);
}
/* 3D 星空的 HTML 概念列表兜底（键盘可达/列表视图） */
.stars-fallback-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 10px;
  margin-top: 16px;
}
.stars-fallback-list button {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  text-align: left;
  padding: 12px 14px;
  border-radius: 12px;
}
.stars-fallback-list span {
  font-size: 11px;
  color: var(--teal);
}
.study-card {
  margin-top: 25px;
  max-width: 900px;
}
.study-card table {
  width: 100%;
  border-collapse: collapse;
  margin: 25px 0;
  font-size: 13px;
}
.study-card th,
.study-card td {
  border-bottom: 1px solid var(--line);
  padding: 15px;
  text-align: left;
  vertical-align: top;
}
.tabs .active,
.row .active {
  background: var(--teal);
  color: white;
}
.lab-number {
  font-size: 40px;
  display: block;
  color: var(--teal);
  padding: 30px;
}
.boundary {
  background: #f2f1e7;
  border-left: 3px solid #b5b99b;
  padding: 14px;
  font-size: 12px;
  line-height: 1.9;
}
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 9px;
  margin: 20px 0;
}
.chips span,
.tag {
  background: var(--soft);
  padding: 7px 12px;
  border-radius: 6px;
  font-size: 12px;
}
.options {
  display: grid;
  gap: 10px;
  margin: 25px 0;
}
.options label {
  display: flex;
  align-items: center;
  gap: 12px;
  border: 1px solid var(--line);
  padding: 13px 17px;
  border-radius: 8px;
  cursor: pointer;
}
.options label.chosen {
  background: var(--soft);
  border-color: var(--teal);
}
.options input {
  min-height: 20px;
  accent-color: var(--teal);
}
.answer {
  margin-top: 25px;
  border-top: 1px solid var(--line);
  padding-top: 14px;
}
.notice,
.alert {
  padding: 14px 18px;
  margin-bottom: 20px;
  border-radius: 9px;
  background: #e2ede2;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 15px;
}
.alert {
  background: #f5e7dd;
  flex-wrap: wrap;
}
.notice button {
  border: 0;
  background: none;
  min-height: 30px;
}
.login {
  margin-bottom: 30px;
}
.login h2 {
  font-size: 17px;
}
.login p {
  font-size: 12px;
  color: var(--muted);
}
.notebook-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 18px;
  margin-top: 25px;
}
.text-button {
  border: 0;
  background: none;
  padding: 0;
  text-align: left;
}
.file-button {
  display: block;
  padding: 9px 16px;
  border: 1px solid var(--line);
  border-radius: 9px;
  position: relative;
  overflow: hidden;
  background: var(--paper);
  cursor: pointer;
}
.file-button input {
  position: absolute;
  inset: 0;
  opacity: 0;
  width: 100%;
  cursor: pointer;
}
.drawer-backdrop,
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: #132f2540;
  z-index: 20;
}
.drawer {
  width: 460px;
  max-width: 100%;
  height: 100%;
  overflow-y: auto;
  margin-left: auto;
  background: var(--paper);
  padding: 40px;
  position: relative;
  box-shadow: -10px 0 60px #14251d14;
}
.drawer h2 {
  font-size: 29px;
}
.drawer .summary {
  font-size: 16px;
  line-height: 1.9;
}
.drawer h3 {
  margin-top: 28px;
}
.close {
  position: absolute;
  right: 18px;
  top: 14px;
  font-size: 24px;
  border: 0;
  background: none;
}
.relation-button {
  display: block;
  text-align: left;
  font-size: 11px;
  margin: 8px 0;
  width: 100%;
}
.modal-backdrop {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  z-index: 30;
}
.modal {
  max-width: 620px;
  width: 100%;
  position: relative;
  padding: 35px;
}
.json-editor {
  height: 360px;
  font-family: monospace;
  font-size: 11px;
}
.revision {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 0;
  border-top: 1px solid var(--line);
}
footer {
  border-top: 1px solid var(--line);
  padding: 22px 42px;
  font-size: 10px;
  color: #929f91;
  display: flex;
  justify-content: space-between;
}
@media (max-width: 1100px) {
  .sidebar {
    width: 200px;
    padding: 28px 17px;
  }
  .workspace {
    margin-left: 200px;
    width: calc(100% - 200px);
  }
  main {
    padding: 30px 26px;
  }
  header {
    padding: 0 26px;
  }
  .chapters {
    grid-template-columns: repeat(3, 1fr);
  }
  .page-heading h1 {
    font-size: 33px;
  }
  .date-card {
    display: none;
  }
  .stats > div {
    padding: 0 18px;
  }
}
@media (max-width: 700px) {
  .shell {
    display: block;
  }
  .sidebar {
    position: static;
    width: 100%;
    height: auto;
    padding: 18px 20px;
    border-right: 0;
    border-bottom: 1px solid var(--line);
  }
  .brand {
    font-size: 16px;
  }
  .brand-icon {
    font-size: 27px;
  }
  .nav-label,
  .sidebar-foot {
    display: none;
  }
  .sidebar nav {
    display: flex;
    overflow-x: auto;
    margin-top: 15px;
    gap: 5px;
  }
  .sidebar nav button {
    white-space: nowrap;
    font-size: 11px;
    padding: 7px 10px;
    min-height: 40px;
  }
  .nav-number {
    display: none;
  }
  .workspace {
    margin: 0;
    width: 100%;
  }
  header {
    height: 61px;
    padding: 0 20px;
  }
  .breadcrumb {
    font-size: 10px;
  }
  .account {
    gap: 7px;
    font-size: 10px;
  }
  main {
    padding: 25px 20px 40px;
  }
  h1,
  .page-heading h1 {
    font-size: 29px;
  }
  .eyebrow {
    font-size: 9px;
    letter-spacing: 2px;
  }
  .page-heading {
    margin: 0 0 20px;
  }
  .stats {
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    padding: 20px 0;
  }
  .stats > div:nth-child(2) {
    border: 0;
  }
  .stats strong {
    font-size: 27px;
  }
  .home-grid,
  .notebook-grid {
    grid-template-columns: 1fr;
  }
  .feature-card,
  .panel {
    padding: 22px;
  }
  .chapters {
    grid-template-columns: repeat(2, 1fr);
    gap: 11px;
  }
  .chapters button {
    padding: 17px;
    min-height: 149px;
  }
  .section-title {
    margin-top: 27px;
    align-items: flex-start;
  }
  .section-title h2 {
    font-size: 17px;
  }
  .section-title button {
    font-size: 11px;
    padding: 8px 10px;
  }
  .toolbar input,
  .toolbar select {
    width: 100%;
  }
  .study-card table {
    font-size: 11px;
  }
  .study-card td,
  .study-card th {
    padding: 9px;
  }
  .drawer {
    padding: 35px 25px;
  }
  .drawer .row button {
    padding: 8px 10px;
  }
  .modal {
    padding: 28px 22px;
  }
  .row {
    gap: 8px;
  }
  .row button {
    font-size: 12px;
  }
  .graph {
    min-height: 300px;
  }
  .graph svg {
    min-width: 700px;
  }
  .graph-node text {
    font-size: 11px;
  }
  .relation-list button {
    width: 100%;
    text-align: left;
  }
  footer {
    padding: 20px;
    font-size: 9px;
  }
  .file-button {
    font-size: 12px;
  }
}
@media (prefers-reduced-motion: reduce) {
  * {
    transition: none !important;
  }
}
</style>
