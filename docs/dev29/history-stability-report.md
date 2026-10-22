# dev.29 史纲恢复稳定性验证

日期：2026-10-22

## 改动

- `apps/web/components/history/ui/HistoryExplorer.vue`
  - 浏览器进入离线状态时先完成待处理的本地持久化，再显示“已保存在本机 · 待同步”；失败时保持“保存失败 · 当前草稿尚未持久保存”。
  - 监听 `online` 后先完成本地持久化，再触发仓库恢复、待同步增量重放和远端刷新；恢复期间显示“正在恢复同步…”。
  - 网络/API 恢复失败时保留本地编辑和“待同步”状态；IndexedDB 持久化失败时保持只读，下一次恢复会先重试内存草稿；恢复成功使用仓库状态显示“已同步到账号”。
  - 重连恢复不再弹出重复的手动恢复提示。
- `tools/tests/dev29-browser.cjs` 增加离线事件后的待同步状态和重连后“已同步到账号”断言。

## 执行命令与结果

- `node --test --test-concurrency=1 tests/history-study.test.mjs tests/history-reconnect.test.mjs`（工作目录 `apps/web`）：17/17 通过。
- `node node_modules/nuxt/bin/nuxt.mjs build`（工作目录 `apps/web`）：构建成功；仅出现 Node 依赖的 trailing-slash deprecation warning。
- 启动独立 Nuxt 产物（端口 3039）后执行 `node tools/tests/dev29-browser.cjs http://localhost:3039`：
  - `PASS AI complete/partial/unsupported/401/history/sanitization`
  - `PASS history offline edit/reconnect/401/pending replay/account switch; no page errors`

浏览器脚本使用隔离 API fixture，覆盖离线编辑、`online` 重连增量回放、401 登录失效保留本地记录、同一浏览器 A→B→A 账号切换隔离和页面错误检查；未调用真实模型、邮件或数据库。持久化失败的只读重试由 `history-reconnect.test.mjs` 覆盖。
