<script setup lang="ts">
import { HeartHandshake } from 'lucide-vue-next'

/**
 * 站点页脚：友情链接区 + 版权行。
 * 友链与赞助入口集中在此维护；外链一律 rel="noopener noreferrer" 新窗口打开。
 */
const friendLinks = [
  { href: 'https://afdian.com/a/wencker', label: '爱发电赞助', note: '支持观澜持续更新', sponsor: true },
]
const year = new Date().getFullYear()
</script>

<template>
  <footer class="site-footer">
    <div class="page-wrap site-footer__inner">
      <section class="site-footer__links" aria-label="友情链接">
        <span class="site-footer__heading">友情链接</span>
        <ul>
          <li v-for="link in friendLinks" :key="link.href">
            <a :href="link.href" target="_blank" rel="noopener noreferrer" class="site-footer__link" :class="{ sponsor: link.sponsor }">
              <HeartHandshake v-if="link.sponsor" :size="14" aria-hidden="true" />
              <span class="site-footer__label">{{ link.label }}</span>
              <small v-if="link.note">{{ link.note }}</small>
            </a>
          </li>
        </ul>
      </section>
      <div class="site-footer__meta">
        <span>观澜 · 考研政治知识库</span>
        <span>© {{ year }} wencker</span>
      </div>
    </div>
  </footer>
</template>

<style scoped>
.site-footer { margin-top: 72px; border-top: 1px solid var(--line); background: color-mix(in srgb, var(--paper) 80%, #fff); }
.site-footer__inner { padding-top: 34px; padding-bottom: 40px; }
.site-footer__heading { display: block; margin-bottom: 14px; color: var(--muted); font-size: 11px; letter-spacing: 2px; }
.site-footer__links ul { display: flex; flex-wrap: wrap; gap: 12px; margin: 0; padding: 0; list-style: none; }
.site-footer__link { display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; border: 1px solid var(--line); border-radius: 4px; background: #fff; color: var(--ink); font-size: 13px; text-decoration: none; transition: border-color .15s, transform .15s; }
.site-footer__link:hover { border-color: var(--red); transform: translateY(-1px); }
.site-footer__link.sponsor { color: var(--red); }
.site-footer__link.sponsor svg { color: var(--red); flex: none; }
.site-footer__link small { color: var(--muted); font-size: 11px; }
.site-footer__meta { display: flex; flex-wrap: wrap; gap: 6px 18px; margin-top: 26px; padding-top: 18px; border-top: 1px dashed var(--line); color: var(--muted); font-size: 11px; }
@media (max-width: 640px) { .site-footer { margin-top: 52px; } }
</style>
