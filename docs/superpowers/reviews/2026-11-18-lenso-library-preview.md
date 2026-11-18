# 观澜视觉重构：资料库与学科详情

在 `apps/web` 执行 `npm run build`，再从仓库根目录执行 `node tools/preview-lenso.mjs 3120`。打开 `http://127.0.0.1:3120/subjects`。如需复现空资料库或接口故障，分别运行 `node tools/preview-lenso.mjs 3121 empty` 或 `node tools/preview-lenso.mjs 3122 error`。

在 Edge 用模拟数据验证进入学科、从目录跳到原有章节锚点、返回资料库，以及空列表、空章节和找不到学科的提示。资料库和详情页在接口返回 503 时均显示失败提示，点击重试会重新请求接口。

两页在 390px 和 1440px 宽度下分别测试浅色和深色，无整页横向溢出。截图位于 `output/playwright/lenso-library/`。111 项测试通过，生产构建成功。未接入生产 API，未部署。
