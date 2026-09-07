# 观澜考研政治知识库实施计划

## 目标

构建一个以全文检索和热点推荐为核心的考研政治资料站：在线后台使用 PHP/Hyperf，前端使用 Nuxt 3，资料导入复用现有 Python 提取/OCR 工具。

## 首版交付

- 搜索优先的响应式首页，展示最新热点、继续阅读和六大科目入口。
- 资料库与热点专题的前端信息架构。
- Hyperf 健康检查与首页聚合 API 骨架。
- Docker Compose 服务编排和 Linux 部署文档。
- 资料导入清单工具，按 SHA-256 去重并输出 177 个学习资产的审计结果。

## 后续批次

- 接入 MySQL/Redis/Meilisearch 实体模型、邀请码认证、阅读进度和收藏。
- 完成 PDF/Word 导入、OCR 队列、PDF.js 阅读器和管理员后台。
- 扩充 PHPUnit、pytest、Vitest 和 Playwright 测试。
