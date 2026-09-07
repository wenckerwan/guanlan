# V0.1-dev.1 学科导航设计

## 目标

将 V0.1 的单页首页扩展为可用的资料导航：六大学科拥有稳定 URL、章节入口和知识点列表，桌面与移动端都能在首页、资料库和学科详情之间往返。

## 范围

本版本只改 Nuxt 前端，不创建 Hyperf 路由、数据库表或登录状态。数据以 TypeScript 静态种子保存，字段设计与未来 API 对齐。

## 架构

- `data/subjects.ts` 保存学科、章节和知识点，使用 slug 作为路由稳定标识。
- `pages/subjects/index.vue` 展示资料库总览。
- `pages/subjects/[slug].vue` 根据 slug 查找学科并展示详情；未知 slug 显示可回到资料库的空状态。
- `components/SiteHeader.vue` 负责桌面主导航和移动端抽屉；首页与学科页共享。
- `components/Breadcrumbs.vue` 负责首页/资料库/学科层级和返回入口。
- 首页只消费共享数据并通过 `NuxtLink` 产生真实跳转。

## 交互规则

1. 学科卡片链接到 `/subjects/<slug>`。
2. “进入资料库”和移动底部“资料”链接到 `/subjects`。
3. 热点条目链接到对应学科，并可携带 `chapter` query 作为未来章节定位钩子。
4. 移动端菜单按钮打开抽屉；点击遮罩、关闭按钮或菜单项后关闭。
5. 面包屑和“返回资料库”在详情页始终可见。
6. 未实现的搜索/我的功能使用非提交按钮并显示“即将开放”，不制造死链接。

## 数据内容

每科提供 2 个代表性章节，每章提供 3 个知识点；内容使用教材目录和已有资料主题的保守摘要，不声称已接入完整资料库。六科 slug 分别为 `marxism`、`maoism`、`xi-jinping-thought`、`modern-china-history`、`ideology-law`、`world-politics`。

## 验证

- Node 单元测试覆盖学科 slug 唯一、热点映射和未知 slug 处理函数。
- `npm test` 必须通过。
- `npm run build` 必须成功。
- 用 `git diff --check` 检查空白错误，并在 CHANGELOG 记录实际输出。
