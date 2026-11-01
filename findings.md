# 后台开发事实与决策

- API 采用 Hyperf，Web 采用 Nuxt/Vue。服务器部署目录与 compose override 已从当前服务器核对。
- API 启动已取消自动 db:seed；现有五类内容保护表开启，导入差异不得自动覆盖。
- 用户管理新响应是分页对象，API/Web 必须配套发布。
- Markdown 与已有 HTML 分别管理，现有 HTML 不自动转 Markdown；草稿暂不新增，保持 published/hidden。
- 对账先提供只读差异与修订，人工明确选择最终来源；不开启网页全量重导入。

- 当前生产API PHP时区已实测为UTC；访问日通过VisitWindow按北京时间记录，学习日通过StatsService.date()以原UTC日期桶累计，只有date+seconds不能恢复北京时间午夜边界。后续总览需明确学习时长的原始记录日口径，注册/作答可从created_at转换北京时间。

- 浏览器发现现有admin/index.vue并不是所有admin子页面的共享布局，直接进入analysis等缺少统一导航；总览入口/admin也未自动展示overview。最终体验阶段引入共享admin布局并保留路由，修复入口和新鲜身份校验。

- 题库批次源码核查：旧AdminService.updateQuestion对options使用array_values会丢A/B/C/D键；试卷编辑未读取sortOrder而初始化0。资料JSON1479题，49道客观题答案为空，62道主观题答案要点/解析均空；新校验需保留读取/排序旧不完整数据，严格验证新增和实际答案相关变更，禁止猜答案。
