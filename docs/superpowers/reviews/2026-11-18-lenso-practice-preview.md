# 时政练习题库视觉重构验证

在 `apps/web` 运行 `npm run build`，再从仓库根目录运行 `node tools/preview-lenso.mjs 3131`。打开 `http://127.0.0.1:3131/practice/current-affairs` 可游客试玩。仅在此本机预览中设置 `guanlan.token=lenso-preview-local` Cookie，可查看模拟账号的 8 道题。

Edge 验证 390px 和 1440px 下的浅色与深色布局：无整页横向溢出，选项触控高度至少 44px。截图位于 `output/playwright/lenso-practice/`。

浏览器走查覆盖题型与收藏筛选、无结果时清除筛选、单选答错、多选答对、展开解析和资料依据、夜读、游客刷新后结果清除、模拟账号收藏、请求中断后重试保存、409 冲突后清除本地记录、加载失败后重试和空题库。打印样式保留，未通过系统打印对话框验证。

`npm test` 111 项通过，`npm run build` 成功，`git diff --check` 无错误。所有模拟请求仅访问本机；未部署。
