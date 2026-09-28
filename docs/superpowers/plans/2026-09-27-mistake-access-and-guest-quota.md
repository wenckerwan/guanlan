# 错题可见性、访客配额与登录态 SSR 实施计划

设计文档：[2026-09-27-mistake-access-and-guest-quota-design.md](../specs/2026-09-27-mistake-access-and-guest-quota-design.md)

状态：已完成（容器内 live 验证待目标服务器执行，见文末「未验证项」）。

---

## Task 1: 数据集移除考生 B

**文件**
- 修改 `tools/ingest/build_mistakes.py`
- 重新生成 `storage/dataset/mistakes.json`
- 重新生成 `storage/dataset-manifest.json`

- [x] 从 `STUDENTS` 移除 B，并清理只在 B 批次出现的注释
- [x] 重跑 `build_mistakes.py`，确认 `students=1 items=91 handbooks=2`
- [x] 重算 manifest，确认 `groups={handbooks:2,items:91,students:1}`
- [x] `bin/verify-dataset.php` 正向通过 + 篡改 items 的负向用例被抓到

## Task 2: 账号 ID 与绑定

**文件**
- 新增 `apps/api/migrations/2026_09_27_000001_add_mistake_code_to_users_table.php`
- 修改 `apps/api/src/Service/AuthService.php`、`Model/User.php`、
  `Resource/UserResource.php`、`Service/AdminService.php`、`Controller/AdminController.php`
- 新增 `apps/api/tests/AccountIdTest.php`

- [x] 迁移增加 `mistake_code VARCHAR(32) NULL UNIQUE`
- [x] 注册取最小未占用纯数字，唯一索引兜底，冲突最多重试 5 次
- [x] 后台 `PATCH /admin/users/{id}` 支持 `mistakeCode`，撞号返回 422
- [x] 个人中心展示账号 ID
- [x] `AccountIdTest` 覆盖取值规则 + 与源码规则一致性（含变异测试）

## Task 3: 错题可见性

**文件**
- 新增 `apps/api/src/Support/MistakeAccess.php`
- 修改 `Controller/MistakeController.php`、`Service/MistakeService.php`、
  `Service/SearchService.php`、`config/routes.php`
- 新增 `apps/api/tests/MistakeAccessTest.php`

- [x] `A` 公开，其余编号仅绑定账号与管理员可见
- [x] 7 个入口全部校验，含 `handbooks/{id}` 反查归属与 `search` 过滤
- [x] 公开路由挂 `AuthMiddleware`（只解析身份，不拦截）
- [x] `MistakeAccessTest` 覆盖公开 / 私有 / 管理员 / 大小写与空白边界

## Task 4: 访客配额

**文件**
- 新增 `apps/api/src/Support/GuestQuota.php`
- 修改 `Controller/ArticleController.php`、`Resource/ArticleResource.php`

- [x] 每栏目 3 篇，按栏目整体顺序计算，与筛选无关
- [x] 列表返回全部条目并带 `locked`
- [x] 详情超额返回 403 `登录后查看完整内容`
- [x] `GuestQuota` 的边界在 `MistakeAccessTest` 中覆盖

## Task 5: 登录引导与前端

**文件**
- 新增 `apps/web/components/LoginGateModal.vue`
- 修改 `components/ArticleGrid.vue`、`utils/articles.mjs`、
  三个栏目列表页与详情页、`pages/mistakes/index.vue`、`pages/mistakes/[code].vue`、
  `pages/mistakes/[code]/[id].vue`、`assets/css/main.css`
- 修改 `apps/web/tests/articles.test.mjs`

- [x] 锁定卡片点击弹窗，不跳转
- [x] 详情 403 回列表并带 `?login=1` 自动弹窗
- [x] 免费 / 锁定分组，顺序完全由服务端决定
- [x] 错题列表页顶部公告
- [x] 修复 `/mistakes/{code}/handbook/{id}` 这个 404 链接
- [x] `splitByLock` 纯函数 + 测试（含脏数据）

## Task 6: 登录态迁移 Cookie

**文件**
- 修改 `apps/web/composables/useAuth.ts`、`composables/useApi.ts`、`app.vue`

- [x] token 存 Cookie（30 天，`SameSite=Lax`，HTTPS 加 `Secure`）
- [x] SSR 从请求 Cookie 同步读取，`app.vue` 首帧拉取用户资料
- [x] `useApiFetch` 透传 Authorization，缓存 key 含 URL 与 token
- [x] 已登录用户不再出现游客态闪烁

## Task 7: 测试接入与文档

**文件**
- 修改 `tools/lint.sh`、`CHANGELOG.md`、`docs/development-plan.md`

- [x] 两个新增 PHP 测试接入容器 lint 流程
- [x] CHANGELOG 记录实际命令输出
- [x] 开发规划更新错题数据基线与权限条目

---

## 验收记录（本机，无 Docker）

| 命令 | 结果 |
|---|---|
| `php -l`（110 个文件） | 0 失败 |
| `tests/MistakeAccessTest.php` | PASS（变异测试可抓到配额破坏） |
| `tests/AccountIdTest.php` | PASS（变异测试可抓到重试逻辑缺失） |
| `bin/verify-dataset.php` | `Dataset integrity OK: 8 files` |
| `python tools/phpcheck.py` | `checked=104 ... OK` |
| `python -m unittest discover -s tools/ingest` | 20/20 |
| `npm test`（web） | 38/38 |
| `npm run build`（web） | 构建成功 5.19 MB |
| SSR 冒烟 `/ /mistakes /analysis /hotspots /predictions /login` | 全部 200 |

## 未验证项

以下需要目标服务器的 Docker 环境，本机（Windows 无 Docker、WSL 无 PHP）无法执行：

- `docker compose up --build -d` 后的 migration 与 Seeder
- `tools/lint.sh`（容器内 PHP lint + 三个测试）
- `tools/smoke.sh`：未登录访问 A 为 200、访问非 A 为 403、
  注册返回递增账号 ID、未登录第 4 篇详情为 403
- 数据库里已存在的考生 B 数据需重新 Seeder 才会清除

在这些跑通并把输出贴进 CHANGELOG 之前，相关条目保持「进行中」。
