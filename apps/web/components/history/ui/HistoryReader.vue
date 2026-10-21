<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { getPrimaryDate } from '../core/index';
import type { DateAssertion, HistoryDataset, HistoryEvent, HistoryRepository, StudyState } from '../core/index';

const props = defineProps<{
  dataset: HistoryDataset; events: HistoryEvent[]; repository: HistoryRepository;
  study: StudyState; selectedId: string | null; query: string; topicIds: string[];
  archive: boolean; windowRange: { start: number; end: number }; studyReadOnly: boolean;
  saveStatus: string; sourcesOffline: boolean; compareIds: string[];
}>();
const emit = defineEmits<{
  'update:query': [value: string]; 'update:topicIds': [value: string[]]; 'update:archive': [value: boolean];
  window: [value: { start: number; end: number }]; select: [event: HistoryEvent];
  bookmark: [event: HistoryEvent]; compare: [event: HistoryEvent]; note: [event: HistoryEvent, value: string]; flush: [];
}>();
const mode = ref<'full' | 'fast'>('full');
const opened = ref<string[]>([]);
const reader = ref<HTMLElement>();
let printSnapshot: { opened: string[]; mode: 'full' | 'fast' } | null = null;
const rows = computed(() => [...props.events].sort((a, b) => (getPrimaryDate(a)?.start ?? Infinity) - (getPrimaryDate(b)?.start ?? Infinity)));
const periods = [
  { label: '全部时期', start: 1839, end: 2027 },
  { label: '旧民主主义革命（1839—1918）', start: 1839, end: 1919 },
  { label: '新民主主义革命（1919—1949）', start: 1919, end: 1949.75 },
  { label: '社会主义革命与建设（1949—1978）', start: 1949.75, end: 1978.95 },
  { label: '改革开放与新时代（1978—2026）', start: 1978.95, end: 2027 },
];
const activePeriod = computed(() => periods.findIndex(p => p.start === props.windowRange.start && p.end === props.windowRange.end));
const topicName = (id: string) => props.dataset.topics.find(t => t.id === id)?.name || '其他';
const citation = (date: DateAssertion) => `${props.dataset.sources.find(s => s.id === date.sourceId)?.title || '来源未登记'}${date.page ? ` · PDF 第 ${date.page} 页` : ' · 页码未定位'}`;
const sourceLink = (date: DateAssertion) => props.sourcesOffline ? null : props.repository.sourceUrl(date.sourceId, date.page);
const eventId = (id: string) => `history-event-${id}`;
function setPeriod(event: Event) { const p = periods[Number((event.target as HTMLSelectElement).value)]; if (p) emit('window', { start: p.start, end: p.end }); }
function reset() { emit('update:query', ''); emit('update:topicIds', []); emit('update:archive', false); emit('window', { ...props.dataset.range }); }
function toggle(event: Event, id: string) { const open = (event.target as HTMLDetailsElement).open; opened.value = open ? [...new Set([...opened.value, id])] : opened.value.filter(value => value !== id); }
async function reveal(id: string, scroll = true) {
  opened.value = [...new Set([...opened.value, id])]; await nextTick();
  if (scroll) document.getElementById(eventId(id))?.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
}
function select(event: HistoryEvent) { emit('select', event); void reveal(event.id); }
function preparePrint() {
  if (printSnapshot) return;
  printSnapshot = { opened: [...opened.value], mode: mode.value };
  opened.value = rows.value.map(event => event.id); mode.value = 'full';
  // Ctrl+P also fires beforeprint; update native details synchronously for the print snapshot.
  reader.value?.querySelectorAll<HTMLDetailsElement>('details.hr-lesson').forEach(detail => { detail.open = true; });
}
function restorePrint() {
  if (!printSnapshot) return;
  const snapshot = printSnapshot; printSnapshot = null;
  opened.value = snapshot.opened; mode.value = snapshot.mode;
  reader.value?.querySelectorAll<HTMLDetailsElement>('details.hr-lesson').forEach(detail => { detail.open = snapshot.opened.includes(detail.id.replace('history-event-', '')); });
}
async function printPage() { preparePrint(); await nextTick(); window.print(); restorePrint(); }
onMounted(() => { window.addEventListener('beforeprint', preparePrint); window.addEventListener('afterprint', restorePrint); });
onBeforeUnmount(() => { window.removeEventListener('beforeprint', preparePrint); window.removeEventListener('afterprint', restorePrint); });
watch(() => props.selectedId, id => { if (id) void reveal(id); }, { immediate: true });
</script>

<template>
  <div class="hr-reader" :class="{ 'hr-fast': mode === 'fast' }">
    <section class="hr-overview" aria-labelledby="hr-overview-title">
      <h2 id="hr-overview-title">先理脉络，再记事件</h2>
      <p class="hr-intro">按时间串联历史，用主题归纳考点；从目录定位事件，再展开阅读与核对出处。</p>
      <div class="hr-stats">
        <div><strong>{{ dataset.events.filter(e => !e.isGroup && e.publication !== 'pending').length }}</strong><span>独立历史事件</span></div>
        <div><strong>{{ dataset.topics.length }}</strong><span>学习主题</span></div>
        <div><strong>{{ study.bookmarks.length }}</strong><span>我的收藏</span></div>
        <div><strong>{{ dataset.sources.length }}</strong><span>参考文献</span></div>
      </div>
      <div class="hr-routes"><div><h3>① 沿时间看脉络</h3><p>选择历史时期，梳理事件的先后顺序。</p></div><div><h3>② 按主题做归纳</h3><p>从革命、思想、制度与经济四条线索阅读。</p></div><div><h3>③ 核对日期与出处</h3><p>区分教材时间与原表时间，结合笔记复习。</p></div></div>
    </section>

    <section id="hr-directory" aria-labelledby="hr-directory-title">
      <h2 id="hr-directory-title">历史事件目录</h2><p class="hr-intro">搜索关键词或筛选时期、主题，点击事件名称进入阅读。</p>
      <div class="hr-tool-panel">
        <div class="hr-toolbar">
          <label class="hr-search">搜索事件<input :value="query" type="search" placeholder="搜索事件、人物、会议、年份…" @input="emit('update:query', ($event.target as HTMLInputElement).value)" /></label>
          <label>历史时期<select :value="activePeriod" @change="setPeriod"><option v-if="activePeriod < 0" :value="-1">已保存的时间范围</option><option v-for="(period, index) in periods" :key="period.label" :value="index">{{ period.label }}</option></select></label>
          <label>学习主题<select :value="topicIds.length === 1 ? topicIds[0] : ''" @change="emit('update:topicIds', ($event.target as HTMLSelectElement).value ? [($event.target as HTMLSelectElement).value] : [])"><option value="">全部主题</option><option v-for="topic in dataset.topics" :key="topic.id" :value="topic.id">{{ topic.name }}</option></select></label>
          <button @click="reset">清除筛选</button>
        </div>
        <div class="hr-result-line"><span role="status">找到 {{ rows.length }} 项{{ archive ? '原表合并组' : '事件' }}</span><label><input :checked="archive" type="checkbox" @change="emit('update:archive', ($event.target as HTMLInputElement).checked)" /> 查看原表合并组</label></div>
        <div v-if="rows.length" class="hr-table-scroll" role="region" aria-label="历史事件目录，可横向滚动" tabindex="0"><table><thead><tr><th>历史事件</th><th>时间</th><th>主题</th><th>内容线索</th></tr></thead><tbody><tr v-for="event in rows" :key="event.id"><td><a :href="`#${eventId(event.id)}`" @click.prevent="select(event)">{{ event.title }}</a></td><td>{{ getPrimaryDate(event)?.label || '日期未知' }}</td><td><span class="hr-tag">{{ topicName(event.topicId) }}</span></td><td class="hr-clue">{{ event.note || event.content }}</td></tr></tbody></table></div>
        <p v-else class="hr-empty">没有符合条件的事件。请缩短关键词，或点击“清除筛选”。</p>
        <p class="hr-source-note">按历史时间排列；教材日期与原表日期分别保留。原表合并组保留原始条目，不将组内事项自动拆分配期。</p>
      </div>
    </section>

    <section id="hr-lessons" ref="reader" aria-labelledby="hr-reader-title">
      <div class="hr-reader-head"><div><h2 id="hr-reader-title">逐事阅读与复习</h2><p class="hr-intro">{{ mode === 'full' ? '完整阅读：事件内容、日期出处与个人笔记。' : '速记复习：集中查看时间、事件与原有备注。' }}</p></div><div class="hr-mode-bar" role="group" aria-label="阅读模式"><button :aria-pressed="mode === 'full'" @click="mode = 'full'">完整阅读</button><button :aria-pressed="mode === 'fast'" @click="mode = 'fast'">速记复习</button><button @click="opened = rows.map(e => e.id)">展开筛选结果</button><button @click="opened = []">收起全部</button><button @click="printPage">打印 / 存为 PDF</button></div></div>
      <p v-if="!rows.length" class="hr-empty">当前筛选没有结果，请在事件目录调整条件。</p>
      <details v-for="(event, index) in rows" :id="eventId(event.id)" :key="event.id" class="hr-lesson" :open="opened.includes(event.id)" @toggle="toggle($event, event.id)">
        <summary><span class="hr-code">{{ String(index + 1).padStart(3, '0') }}</span><span><strong>{{ event.title }}</strong><small>{{ getPrimaryDate(event)?.label || '日期未知' }} · {{ topicName(event.topicId) }}<span v-if="study.bookmarks.includes(event.id)"> · 已收藏</span></small></span><span class="hr-expand" aria-hidden="true">{{ opened.includes(event.id) ? '收起 −' : '展开 ＋' }}</span></summary>
        <div class="hr-lesson-body">
          <p v-if="event.isGroup" class="hr-memory">原表合并组：以下事项沿用原表记录，组内事件未逐项核定日期。</p>
          <h3>{{ mode === 'fast' ? '时间与事件' : '事件内容' }}</h3><p class="hr-content">{{ event.content }}</p>
          <div v-if="event.note" class="hr-memory"><h4>备注与阅读线索</h4><p>{{ event.note }}</p></div>
          <div class="hr-full-only">
            <h3>日期与出处</h3><div class="hr-date-grid"><div v-for="kind in (['textbook', 'original'] as const)" :key="kind"><span>{{ kind === 'textbook' ? '教材时间' : '原表时间' }}</span><template v-if="event.dates[kind]"><strong>{{ event.dates[kind]!.label }}</strong><a v-if="sourceLink(event.dates[kind]!)" :href="sourceLink(event.dates[kind]!)!" target="_blank" rel="noopener">{{ citation(event.dates[kind]!) }} ↗</a><small v-else>{{ citation(event.dates[kind]!) }} · 原页暂不可用</small></template><small v-else>{{ kind === 'textbook' ? '未找到可靠教材定位' : '原表未单列' }}</small></div></div>
            <p class="hr-source-note">{{ sourcesOffline ? '离线状态，原文链接暂不可用。' : '原文链接需要对应 PDF 文档服务可用。' }}</p>
            <div class="hr-actions"><button :disabled="studyReadOnly" :aria-pressed="study.bookmarks.includes(event.id)" @click="emit('bookmark', event)">{{ study.bookmarks.includes(event.id) ? '已收藏' : '收藏事件' }}</button><button :aria-pressed="compareIds.includes(event.id)" @click="emit('compare', event)">{{ compareIds.includes(event.id) ? '移出比较' : '加入比较' }}</button></div>
            <label class="hr-note">我的笔记 <small>{{ saveStatus }}</small><textarea :value="study.notes[event.id] || ''" :readonly="studyReadOnly" :aria-label="`${event.title}的个人笔记`" placeholder="记下你的理解、易混点或回忆线索…" rows="3" @input="emit('note', event, ($event.target as HTMLTextAreaElement).value)" @blur="emit('flush')" /></label>
            <div v-if="event.tags.length" class="hr-tags"><span v-for="tag in event.tags" :key="tag" class="hr-tag">{{ tag }}</span></div>
          </div>
        </div>
      </details>
    </section>
    <a class="hr-back-top" href="#hr-directory">返回目录 ↑</a>
  </div>
</template>

<style scoped>
.hr-reader{color:#29384b;font:16px/1.8 "Microsoft YaHei","PingFang SC",system-ui,sans-serif}.hr-reader *{box-sizing:border-box}.hr-reader section{padding:30px 0;scroll-margin-top:80px}.hr-reader h2{margin:0 0 8px;color:#174875;font-size:25px;line-height:1.45}.hr-reader h3{color:#174875;font-size:17px;margin:12px 0 6px}.hr-reader h4{margin:0;color:#174875}.hr-intro{margin:0 0 18px;color:#64758a;font-size:14px}.hr-reader a{color:#1e5d99;text-underline-offset:4px}.hr-reader button,.hr-reader input,.hr-reader select,.hr-reader textarea{font:inherit;color:inherit}.hr-reader button{min-height:44px;padding:8px 14px;border:1px solid #bdcddd;border-radius:5px;background:#fff;cursor:pointer;font-size:14px;transition:background-color .15s,color .15s}.hr-reader button:hover{background:#eaf2fb}.hr-reader button[aria-pressed=true]{background:#1c5487;border-color:#1c5487;color:#fff}.hr-reader button:disabled{opacity:.5;cursor:not-allowed}.hr-reader :focus-visible{outline:3px solid #3982c5;outline-offset:3px}.hr-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.hr-stats>div{background:#fff;border:1px solid #dae4ef;border-radius:6px;padding:16px 20px}.hr-stats strong{display:block;font-size:30px;color:#174875;line-height:1.3;font-variant-numeric:tabular-nums}.hr-stats span{font-size:14px;color:#64758a}.hr-routes{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:24px}.hr-routes>div{border-top:2px solid #3876ae}.hr-routes p{font-size:14px;color:#64758a;margin:0}.hr-tool-panel{padding:20px;background:#fff;border:1px solid #dae4ef;border-radius:6px}.hr-toolbar{display:flex;align-items:end;gap:14px;flex-wrap:wrap}.hr-toolbar>label{display:flex;flex:1;min-width:145px;flex-direction:column;gap:5px;font-size:13px;color:#64758a}.hr-toolbar>.hr-search{flex:2;min-width:230px}.hr-toolbar input,.hr-toolbar select,.hr-note textarea{min-height:44px;width:100%;padding:8px 12px;border:1px solid #bdcddd;border-radius:4px;background:#fff;font-size:15px}.hr-result-line{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px;color:#64758a;font-size:13px}.hr-result-line label{display:flex;gap:7px;align-items:center}.hr-result-line input{width:16px;height:16px;accent-color:#1c5487}.hr-table-scroll{overflow:auto;max-height:450px;border:1px solid #dae4ef;border-radius:4px;margin-top:18px;overscroll-behavior:contain}.hr-reader table{width:100%;min-width:780px;border-spacing:0;background:#fff}.hr-reader th,.hr-reader td{padding:11px 13px;text-align:left;vertical-align:top;border-bottom:1px solid #dae4ef;font-size:14px}.hr-reader th{position:sticky;top:0;z-index:2;color:#174875;background:#eaf1f8;white-space:nowrap}.hr-reader td:first-child,.hr-reader th:first-child{position:sticky;left:0;min-width:210px;max-width:300px;background:#fff;z-index:1}.hr-reader th:first-child{background:#eaf1f8;z-index:3}.hr-reader td:nth-child(2){min-width:130px;font-variant-numeric:tabular-nums}.hr-clue{min-width:260px;max-width:420px;white-space:pre-line}.hr-tag{display:inline-block;padding:1px 7px;color:#245e90;background:#eaf2fb;font-size:12px;border-radius:3px;white-space:nowrap}.hr-source-note{font-size:12px;color:#64758a;margin:14px 0 0}.hr-empty{padding:24px;text-align:center;color:#64758a;border:1px dashed #bdcddd;background:#fff}.hr-reader-head{display:flex;gap:16px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:16px}.hr-mode-bar{display:flex;gap:8px;flex-wrap:wrap}.hr-lesson{margin:12px 0;background:#fff;border:1px solid #dae4ef;border-radius:6px;scroll-margin-top:80px;overflow:clip}.hr-lesson summary{display:grid;grid-template-columns:48px 1fr auto;gap:12px;padding:17px 20px;cursor:pointer;list-style:none;align-items:start}.hr-lesson summary::-webkit-details-marker{display:none}.hr-lesson summary:hover{background:#f3f7fc}.hr-lesson[open]>summary{border-bottom:1px solid #dae4ef}.hr-lesson summary strong{font-size:18px;line-height:1.5;color:#174875}.hr-lesson summary small{display:block;font-size:13px;color:#64758a;margin-top:4px}.hr-code,.hr-expand{font-size:13px;color:#2d6a9f;padding-top:3px}.hr-code{font-variant-numeric:tabular-nums}.hr-lesson-body{padding:20px 30px}.hr-content,.hr-memory p{white-space:pre-line;overflow-wrap:anywhere;margin:8px 0}.hr-memory{border-left:3px solid #3876ae;background:#edf4fc;padding:12px 16px;margin:18px 0}.hr-date-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.hr-date-grid>div{padding:14px 16px;background:#f5f8fc;border:1px solid #e1e9f2;border-radius:4px;min-width:0}.hr-date-grid span,.hr-date-grid strong,.hr-date-grid a,.hr-date-grid small{display:block;overflow-wrap:anywhere}.hr-date-grid span{font-size:13px;color:#64758a}.hr-date-grid strong{font-size:17px;color:#174875;margin:4px 0}.hr-date-grid a,.hr-date-grid small{font-size:13px}.hr-actions{display:flex;gap:8px;margin:20px 0}.hr-note{display:block;font-size:14px;color:#174875}.hr-note small{color:#64758a;margin-left:8px}.hr-note textarea{display:block;margin-top:6px;resize:vertical}.hr-tags{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.hr-fast .hr-full-only{display:none}.hr-back-top{position:fixed;right:20px;bottom:20px;background:#fff;padding:8px 13px;border:1px solid #dae4ef;border-radius:4px;font-size:13px;z-index:8;box-shadow:0 3px 10px #17487514}
@media(max-width:700px){.hr-stats{grid-template-columns:1fr 1fr;gap:10px}.hr-stats>div{padding:14px}.hr-routes{grid-template-columns:1fr;gap:16px}.hr-tool-panel{padding:14px}.hr-toolbar{gap:10px}.hr-toolbar>label{min-width:0;flex-basis:calc(50% - 5px)}.hr-toolbar>.hr-search{min-width:0;flex-basis:100%}.hr-toolbar>button{width:100%}.hr-lesson summary{grid-template-columns:34px 1fr;padding:15px 13px;gap:7px}.hr-expand{grid-column:2}.hr-lesson summary strong{font-size:17px}.hr-lesson-body{padding:15px}.hr-date-grid{grid-template-columns:1fr}.hr-mode-bar{gap:6px}.hr-mode-bar button{padding:8px 10px}.hr-back-top{right:12px;bottom:12px}.hr-reader h2{font-size:23px}}
@media print{.hr-toolbar,.hr-result-line,.hr-mode-bar,.hr-back-top,.hr-actions,.hr-note,.hr-tags,#hr-directory{display:none!important}.hr-reader section{padding:12px 0}.hr-reader .hr-lesson{content-visibility:visible}.hr-lesson::details-content{content-visibility:visible!important;display:block!important;height:auto!important}.hr-lesson-body,.hr-full-only{display:block!important}.hr-lesson summary{break-after:avoid}.hr-expand{display:none}.hr-lesson,.hr-stats>div{border-color:#bbb}.hr-date-grid>div,.hr-memory{background:#fff}.hr-reader{font-size:10pt}.hr-reader a{color:#000}.hr-reader h2{font-size:18pt}}
</style>
