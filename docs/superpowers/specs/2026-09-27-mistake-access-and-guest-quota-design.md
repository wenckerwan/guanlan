# 错题可见性、访客配额与登录态 SSR 设计

## 目标

1. 考生 A 的错题作为公开模板，未登录与所有登录用户均可查看。
2. 其他考生的错题仅「本人账号」与管理员可见，服务端硬校验，不可被绕过。
3. 未登录用户在每个文章栏目（真题分析 / 时政热点 / 时政预测）只能看 3 篇，第 4 篇起需登录。
4. 错题列表页顶部增加公告。
5. 登录态可在 SSR 阶段解析，消除「首屏显示游客态、客户端再闪一次」的问题。

## 账号 ID 与绑定

- 注册成功后由系统生成**数字递增**的账号 ID，写入 `users.mistake_code`。
- 一个账号 ID 绑定一个账号，并同时作为该账号对应的错题编号（`mistake_students.code`）。
- `users.mistake_code` 为 `VARCHAR(32) NULL UNIQUE`：允许多行 NULL（未绑定），非 NULL 时唯一。
- 生成规则：从 1 开始取**最小未被占用的纯数字**（1、2、3…），保证递增且不复用已释放的编号。
  实现上只 `pluck` 非空的 `mistake_code` 列（不载入整行），写入依赖唯一索引兜底。
- 管理员账号不自动绑定（保持 NULL），管理员通过角色获得全量可见性。
- 管理员可在后台用户列表中查看并改绑某账号的考生编号。

## 错题可见性

- 公开编号固定为 `A`，任何访问者可见。
- 其余编号：仅「`mistake_code` 与编号相等」的账号，或管理员可见。
- 可见性判断集中在新增的 `App\Support\MistakeAccess`，所有入口复用同一判断：
  - `GET /mistakes/students`：列表只返回可见考生。
  - `GET /mistakes/students/{code}`：不可见返回 403。
  - `GET /mistakes/students/{code}/items`：不可见返回 403。
  - `GET /mistakes/students/{code}/handbooks`：不可见返回 403。
  - `GET /mistakes/students/{code}/detail`：不可见返回 403。
  - `GET /mistakes/handbooks/{id}`：先反查手册归属考生，再判断可见性，避免绕过 code 直取。
  - `PATCH /mistakes/items/{id}/action`：除登录外，还要求操作者是管理员或该错题的归属账号。
  - `GET /search`：错题类结果只保留可见编号的数据。
- 这些路由统一挂 `AuthMiddleware`（只解析身份、不拦截），未登录时解析为匿名。

## 访客文章配额

- 配额为每栏目各 3 篇，三个栏目互不影响。
- 顺序以服务端既有排序为准（分析：release desc, sort_order；热点：priority, published_at desc, id；预测：sort_order）。
- 列表接口仍返回全部条目；未登录时，超出配额条目的 `locked` 为 `true`。
- 详情接口：未登录且目标条目在配额之外时返回 403（`message` 为「登录后查看完整内容」）。
- 登录用户不受配额限制，`locked` 恒为 `false`。

## 前端提示

- 新增可复用组件 `LoginGateModal.vue`：标题「登录后查看完整内容」，说明文案 + 两个动作「去登录」（携带 `redirect` 回跳当前地址）与「先看看别的」（关闭）。
- 列表页：锁定卡片不跳转，点击即打开弹窗；正常卡片照常跳转。
- 详情页：直达被 403 拦截时，跳回对应列表页并带 `?login=1`，列表页据该参数自动打开弹窗。
- 锁定卡片在列表中以独立分组展示，避免「锁住的卡片排在免费卡片之前」造成的顺序歧义。

## 登录态 SSR

- token 由 `localStorage` 迁移到 Cookie（`guanlan.token`，`SameSite=Lax`，30 天；HTTPS 下附加 `Secure`）。
- `useAuth` 在服务端通过 `useRequestHeaders(['cookie'])` 读取 token，客户端通过 `document.cookie` 读写。
- `useApiFetch` 在服务端与客户端都按 token 附加 `Authorization: Bearer` 头，并把 token 纳入 `useFetch` 的 `key`，避免不同身份命中同一份缓存。
- 退出登录时清除 Cookie 与本地用户信息。

## 错题列表公告

仅在 `/mistakes` 列表页顶部展示：

> 错题分析服务会消耗 token，暂不支持免费分析，有需要可以联系管理员 QQ 206405650

## 数据变更

- 从 `storage/dataset/mistakes.json` 删除考生 B 的 student、items、handbooks。
- 同步更新 `storage/dataset-manifest.json` 中 `mistakes.json` 的 bytes、sha256、groups、items 与 totals。
- `tools/ingest/build_mistakes.py` 的 `STUDENTS` 移除 B，避免重新生成时回归。
- 不改动 `storage/raw`，因此 `storage/import-manifest.json` 与 source_manifest 摘要保持不变。
- 服务端需重新执行 Seeder（或重新部署）才会清除数据库中已有的 B 数据。

## 实施产物

- 后端：`src/Support/MistakeAccess.php`、`src/Support/GuestQuota.php`、
  `migrations/2026_09_27_000001_add_mistake_code_to_users_table.php`。
- 后端测试：`tests/MistakeAccessTest.php`（可见性 + 配额）、`tests/AccountIdTest.php`（编号规则）。
- 前端：`components/LoginGateModal.vue`、`utils/articles.mjs:splitByLock()`。
- 已接入 `tools/lint.sh`，容器内会执行上述两个新增 PHP 测试。

## 非目标

- 不实现付费/配额购买。
- 不实现按账号单独配置配额数量。
- 不实现错题数据在线上传界面，编号仍由数据构建流程生成。

## 验收

- 未登录：可看 A 的全部错题与手册；访问非 A 编号返回 403；每个文章栏目恰好 3 篇可读，其余列表可见但点击弹登录。
- 绑定账号：可看 A 与自己编号的错题，看不到其他编号。
- 管理员：可见全部编号，可改绑账号编号。
- 搜索结果不泄露不可见编号的错题。
- 注册后 `mistake_code` 为递增纯数字且唯一。
- 已登录用户 SSR 首屏即为登录态，不出现游客态闪烁。
