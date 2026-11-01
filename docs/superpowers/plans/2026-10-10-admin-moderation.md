# 错题与评论管理 Implementation Plan

> **For agentic workers:** Use subagent-driven-development for independent tasks with whole-branch review.

**Goal:** 完成已确认的考生/错题检索和评论管理，并明确生产验收边界。

**Architecture:** Hyperf专用读服务与现有控制器，Nuxt页面保持后台布局；分别维护URL、请求世代和表单保存上下文。

**Tech Stack:** Hyperf/PHP、MySQL8、Nuxt3/Vue、Playwright。

## Global Constraints

玻璃主题和现有路由；不读取用户认证信息；生产写入仅明确测试内容；暂不新增数据库迁移；既有错误类型为自由文本精确匹配。

## Tasks

- [x] 生产浏览器真实流程：用户亲自登录；时政测试记录读取/编辑/保存/恢复/冲突验证与精确清理，记录实际边界。
- [x] 后端：AdminMistakeListService及CommentService.adminList/控制器参数与422；新增AdminModerationIntegrationTest，先失败后修复，更新临时MySQL runner。
- [x] 评论页面：组合筛选、URL、请求隔离、权限错误、当前页操作刷新、确认、移动端与浏览器回归。
- [x] 错题页面：考生分页/关键词与错题筛选、URL恢复、统计/画像/条目错误与重试、请求和草稿隔离、浏览器回归。
- [x] 全分支审查和回归：真实MySQL、前端55项、构建、浏览器、PHP语法/文档；提交推送开发分支，部署另按用户授权。
