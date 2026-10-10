# 题库维护 Implementation Plan

> **For agentic workers:** Use subagent-driven-development for independent API, paper UI and library UI tasks, then whole-branch review.

**Goal:** 补齐题目新增、稳定身份排序和正确选项保存，提供模拟卷详情与预测排序。

**Architecture:** 专用问题服务/控制器负责校验、版本与事务；模拟卷只读服务，Nuxt页面分别隔离读取和保存上下文。

**Tech Stack:** Hyperf/PHP、MySQL8、Nuxt3/Vue、Playwright。

## Global Constraints

继承main玻璃UI；不修改生产；题目pid/no/id稳定；排序独立，不全量导入；题型与字母选项一致；PATCH expectedRevision必填；新增questions排序/修订迁移；本批基于5f06dbc。

- [ ] 后端TDD：迁移、Question/Library服务与控制器、旧选项写路径修复、排序资源、预测校验，新增真实MySQL/HTTP并回归既有fixture。
- [ ] 真题管理TDD：题目新增与完整编辑、字母选项检查、排序、冲突、草稿/保存隔离，试卷排序载入；浏览器验证。
- [ ] 模拟卷与预测TDD：只读分页详情、URL/迟到响应隔离，预测排序与原操作反馈；浏览器验证。
- [ ] 逐项及全分支审查；完整回归、PHP/构建/文档，版本记录、提交推送开发分支；发布另按授权。
