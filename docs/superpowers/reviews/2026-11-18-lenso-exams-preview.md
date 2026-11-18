# 模拟押题与真题入口预览

在 `apps/web` 运行 `npm run build`，然后从仓库根目录运行 `node tools/preview-lenso.mjs 3134`。本地预览入口为 `/mocks` 和 `/papers`，使用样板数据。

Edge 检查模拟卷列表、模拟卷详情和真题列表在 390px 与 1440px 的浅色、深色布局，共 12 张截图，页面无横向溢出。截图位于 `output/playwright/lenso-exams/`。

浏览器走查覆盖模拟卷入口和面包屑、单选与多选、交卷后焦点和得分、材料题解析、清空重做、模拟卷不存在，以及真题的模块请求、卷型和关键词筛选、清除筛选、请求失败重试、进入真题详情。真题模块筛选使用现有 API 参数；后端与模拟卷判分函数未改动。

`npm test` 111 项通过，`npm run build` 成功，`git diff --check` 无错误。未部署。
