#!/usr/bin/env node
/**
 * 时政事件条目推送工具（seed 式数据管）
 *
 * 用途：把审核好的事件长文写进 storage/dataset/hotspots.json，并自动重算
 *       storage/dataset-manifest.json（bytes/items/sha256/totals），
 *       保证部署时 DatasetManifestVerifier 校验通过、shizheng:import 灌库成功。
 *
 * 用法：
 *   node tools/shizheng-push.mjs --input ./事件.json            # 追加
 *   node tools/shizheng-push.mjs --input ./事件.json --replace  # 整表替换（按 date 排序）
 *   node tools/shizheng-push.mjs --check                        # 只校验并重算 manifest
 *
 * 输入是事件对象或数组，字段：
 *   {
 *     "slug":     "ev-20261112-3fa2c1",   // 必填，全库唯一
 *     "title":    "纪念孙中山先生诞辰160周年大会", // 必填，全库唯一
 *     "date":     "2026-11-12",           // 必填，事件发生日
 *     "module":   "史纲",                  // 必填，习思想/毛中特/史纲/马原/思法/当代
 *     "priority": "S",                     // 可选，S/A/B，默认 A
 *     "period":   "2026年11月",            // 可选，缺省由 date 推出
 *     "summary":  "……",                   // 可选，列表摘要
 *     "html":     "<h2>…</h2>",           // 必填，长文正文（HTML）
 *     "outline":  [{"level":2,"title":"…"}], // 可选，缺省从 html 的 h2/h3 提取
 *     "word_count": 1234,                  // 可选，缺省按纯文本字数统计
 *     "source_file": "孙中山诞辰160周年专题_2026.md"
 *   }
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const HOTSPOTS = resolve(ROOT, 'storage/dataset/hotspots.json');
const MANIFEST = resolve(ROOT, 'storage/dataset-manifest.json');
const DATASET_DIR = resolve(ROOT, 'storage/dataset');

const args = process.argv.slice(2);
const opt = { input: null, check: false, replace: false };
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--input') opt.input = args[++i];
  else if (args[i] === '--check') opt.check = true;
  else if (args[i] === '--replace') opt.replace = true;
}

const MODULES = ['习思想', '毛中特', '史纲', '马原', '思法', '当代'];

function fail(msg) {
  console.error('✗ ' + msg);
  process.exit(1);
}
function ok(msg) {
  console.log('✓ ' + msg);
}
function readJson(path) {
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch (e) {
    fail(`无法解析 JSON: ${path}\n  ${e.message}`);
  }
}
function sha256(buf) {
  return createHash('sha256').update(buf).digest('hex');
}
// 统计 html 纯文本字数（去标签、去空白）
function textOf(html) {
  return String(html).replace(/<[^>]*>/g, '').replace(/\s+/g, '');
}
// 从 html 提取 outline（h2/h3）
function extractOutline(html) {
  const out = [];
  const re = /<h([23])[^>]*>([\s\S]*?)<\/h\1>/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    const title = m[2].replace(/<[^>]*>/g, '').trim();
    if (title) out.push({ level: Number(m[1]), title });
  }
  return out;
}

function validateEntry(e, idx, existingSlugs, existingTitles) {
  const where = `第 ${idx + 1} 条`;
  if (typeof e !== 'object' || e === null) fail(`${where}: 不是对象`);
  for (const k of ['slug', 'title', 'html']) {
    if (!e[k] || typeof e[k] !== 'string') fail(`${where}: 缺少必填字符串字段 ${k}`);
  }
  if (!e.period && !e.date) fail(`${where}: 需要 period 或 date 之一`);
  if (e.date && !/^\d{4}-\d{2}-\d{2}$/.test(e.date)) fail(`${where}: date 须为 YYYY-MM-DD`);
  if (existingSlugs.has(e.slug)) fail(`${where}: slug 重复 → ${e.slug}`);
  if (existingTitles.has(e.title)) fail(`${where}: title 重复 → ${e.title}`);
  if (e.priority && !['S', 'A', 'B'].includes(e.priority)) fail(`${where}: priority 须为 S/A/B`);
  if (e.outline && !Array.isArray(e.outline)) fail(`${where}: outline 须为数组`);
  if (e.module && !MODULES.includes(e.module)) fail(`${where}: module 须为 ${MODULES.join('/')}`);
}

// 事件条目：period 缺省时由 date 推成「2026年11月」这样的月份分组
function periodOf(e) {
  if (e.period) return e.period.trim();
  const [y, m] = e.date.split('-');
  return `${Number(y)}年${Number(m)}月`;
}

function normalizeEntry(e) {
  const entry = {
    slug: e.slug.trim(),
    title: e.title.trim(),
    period: periodOf(e),
    date: (e.date || '').trim(),
    priority: e.priority || 'A',
    summary: (e.summary || '').trim(),
    html: e.html,
    outline: Array.isArray(e.outline) && e.outline.length ? e.outline : extractOutline(e.html),
    word_count: Number.isInteger(e.word_count) && e.word_count > 0 ? e.word_count : textOf(e.html).length,
    source_file: (e.source_file || '').trim(),
  };
  for (const k of ['module', 'level', 'type', 'tag']) {
    if (e[k]) entry[k] = String(e[k]).trim();
  }
  return entry;
}

// 重算单个 dataset 的 manifest 条目（与 DatasetManifestVerifier.describeDataset 同规则）
function describeDataset(payload) {
  if (Array.isArray(payload)) return { items: payload.length, groups: {} };
  const groups = {};
  let items = 0;
  for (const [k, v] of Object.entries(payload)) {
    if (Array.isArray(v)) {
      groups[k] = v.length;
      items += v.length;
    }
  }
  const sorted = Object.fromEntries(Object.entries(groups).sort(([a], [b]) => (a < b ? -1 : 1)));
  return { items, groups: sorted };
}

function rebuildManifest() {
  const manifest = readJson(MANIFEST);
  const datasets = manifest.datasets || {};
  let totalBytes = 0;
  let totalItems = 0;
  let files = 0;
  for (const name of Object.keys(datasets)) {
    const path = resolve(DATASET_DIR, name);
    let buf;
    try {
      buf = readFileSync(path);
    } catch {
      fail(`manifest 引用了缺失文件: ${name}`);
    }
    const payload = readJson(path);
    const { items, groups } = describeDataset(payload);
    datasets[name] = { bytes: buf.length, groups, items, sha256: sha256(buf) };
    totalBytes += buf.length;
    totalItems += items;
    files++;
  }
  manifest.datasets = datasets;
  manifest.totals = { bytes: totalBytes, files, items: totalItems };
  writeFileSync(MANIFEST, JSON.stringify(manifest, null, 2) + '\n', 'utf8');
  return manifest.totals;
}

// ---- 主流程 ----
const hotspots = readJson(HOTSPOTS);
if (!Array.isArray(hotspots)) fail('hotspots.json 根必须是数组');

if (opt.check) {
  const totals = rebuildManifest();
  ok(`hotspots.json 共 ${hotspots.length} 期；manifest 已重算 → totals: ${totals.files} 文件 / ${totals.items} 项 / ${totals.bytes} 字节`);
  console.log('提示: 在服务器或本地跑 DatasetManifestVerifier（api 启动 seed 时会自动跑）确认 integrity OK。');
  process.exit(0);
}

if (!opt.input) fail('缺少 --input <新一期.json>，或用 --check 仅校验/重算 manifest');

const incomingRaw = readJson(resolve(process.cwd(), opt.input));
const incoming = Array.isArray(incomingRaw) ? incomingRaw : [incomingRaw];

const existingSlugs = new Set(hotspots.map((h) => h.slug));
const existingTitles = new Set(hotspots.map((h) => h.title));
incoming.forEach((e, i) => validateEntry(e, i, existingSlugs, existingTitles));

const normalized = incoming.map(normalizeEntry);

// 新增 period 提醒：CARD_MAP 需补映射，否则落 fallback
const knownPeriods = new Set(hotspots.map((h) => h.period));
const newPeriods = [...new Set(normalized.map((h) => h.period))].filter((p) => !knownPeriods.has(p));

const sortKey = (h) => (h.date || `${h.period}-01`);
const merged = opt.replace
  ? [...normalized].sort((a, b) => sortKey(a).localeCompare(sortKey(b)))
  : hotspots.concat(normalized);
writeFileSync(HOTSPOTS, JSON.stringify(merged, null, 2) + '\n', 'utf8');
ok(`${opt.replace ? '已整表替换' : '已追加'} ${normalized.length} 条 → hotspots.json 现共 ${merged.length} 条`);

const totals = rebuildManifest();
ok(`manifest 已重算 → totals: ${totals.files} 文件 / ${totals.items} 项 / ${totals.bytes} 字节`);

if (newPeriods.length) {
  console.warn('\n⚠ 检测到新 period（未在 ArticleSeeder.CARD_MAP 配置；条目自带 level/type/tag 或以 date+module 推送时不受影响）:');
  for (const p of newPeriods) console.warn(`   '${p}' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '在这里起个标签'],`);
}

console.log('\n下一步:');
console.log('  1. （如有新 period）补 ArticleSeeder.CARD_MAP，php -l 校验');
console.log('  2. git add storage/dataset/hotspots.json storage/dataset-manifest.json apps/api/seeders/ArticleSeeder.php');
console.log('  3. 提交并推送，部署后 api 启动时自动 seed（幂等，按 slug 清空重灌 whereNotNull(slug)）');
