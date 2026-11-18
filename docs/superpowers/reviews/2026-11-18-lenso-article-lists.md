# 观澜视觉重构：文章列表

在 `apps/web` 运行 `npm run build`，再从仓库根目录运行 `node tools/preview-lenso.mjs 3123`。本地预览路径为 `/hotspots`、`/predictions` 和 `/analysis`。用 `node tools/preview-lenso.mjs 3124 normal empty` 和 `node tools/preview-lenso.mjs 3125 normal error` 可复现空列表与接口故障。

在 Edge 验证三页在 390px、1440px 下的浅色和深色共 12 种组合，未发现整页横向溢出。分别核对分组、优先级显示、免费文章打开与返回、键盘触发登录提示、直接访问锁定详情后的 403 回跳、空列表、请求失败和重试。时政页还验证了去登录时保留列表查询参数。

截图位于 `output/playwright/lenso-articles/`。111 项测试通过，生产构建成功。验证使用本地模拟 API，没有实际登录或部署。
