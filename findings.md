# 后台开发事实与决策

- API 采用 Hyperf，Web 采用 Nuxt/Vue。服务器部署目录与 compose override 已从当前服务器核对。
- API 启动已取消自动 db:seed；现有五类内容保护表开启，导入差异不得自动覆盖。
- 用户管理新响应是分页对象，API/Web 必须配套发布。
- Markdown 与已有 HTML 分别管理，现有 HTML 不自动转 Markdown；草稿暂不新增，保持 published/hidden。
- 对账先提供只读差异与修订，人工明确选择最终来源；不开启网页全量重导入。
