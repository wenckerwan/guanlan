# 错题系统重构实施计划

目标：管理员固定 000001，普通用户按注册顺序获得六位账号；注册自动创建对应空错题本和默认分析文档；重构错题数据和复习状态，建立可持续的查看、重练、复习、统计闭环。

架构：新增独立账号序列表；mistake_students 表负责错题本归属；mistake_profiles 表负责 Markdown 分析文档；mistake_items 使用稳定 item_key；mistake_reviews 保存用户个人复习状态。A 保留为公开的“错题分析示例”，普通用户注册后自动创建 owner_user_id 对应的空错题本。

全局约束：
- 管理员使用 000001；普通用户从 000002 开始。
- 六位账号不回收、不复用。
- 最终介绍文案和 skills 暂时使用可替换占位，不写死最终内容。
- 继续使用 Markdown 到 HTML 的现有阅读链路，不做 PDF。
- 基础错题内容不得被个人重练修改。
- Seeder 必须幂等，不得清空用户错题本、复习记录或个人文档。
- 每个新行为先写失败测试，再写实现。

## Task 1：账号序列和自动绑定

文件：
- 新增 apps/api/migrations/2026_09_28_000001_create_mistake_accounts_table.php
- 新增 apps/api/migrations/2026_09_28_000002_add_owner_user_id_to_mistake_students.php
- 修改 User.php、MistakeStudent.php、AuthService.php、UserResource.php
- 扩展 tests/AccountIdTest.php

行为：
- 管理员迁移为账号 000001。
- 普通用户按 created_at/id 顺序获得 000002、000003……。
- 注册在同一个数据库事务中完成：用户、账号记录、空错题本、默认分析文档。
- 账户编号通过独立自增表产生，删除或禁用不回收。
- API 对外返回 accountId，例如 000002，不再把可复用的 mistake_code 当账号源头。

## Task 2：默认分析文档

文件：
- 新增 mistake_profiles 迁移、模型和服务
- 修改 MistakeResource、MistakeController
- 新增 MistakeProfileTest.php

行为：
- 每个新账号自动有一份分析文档。
- 默认内容只有一行可替换占位文案。
- 文档保存 Markdown 原文和渲染后的 HTML。
- 后续管理员上传 Markdown 后，更新同一个账号的当前文档，不生成重复默认文档。
- 用户稍后提供的介绍和 skills 内容只替换占位内容，不影响数据结构。

## Task 3：稳定题目标识和安全 Seeder

文件：
- 新增 mistake_items.item_key 迁移
- 修改 build_mistakes.py、MistakeItem、MistakeResource、MistakeSeeder
- 新增 MistakeSeederIdempotencyTest.php

行为：
- 每道题生成稳定 item_key，不依赖数据库自增 id。
- A 的显示名称改为“错题分析示例”，关系改为“公开示例”。
- Seeder 改为按 student code + item_key 幂等更新。
- 不再删除所有错题学生和错题条目。
- 已注册但没有导入内容的用户空错题本必须保留。

## Task 4：个人复习状态和原子重练接口

文件：
- 新增 mistake_reviews 迁移、模型、服务
- 新增 AnswerEvaluator
- 修改 MistakeController、路由、Attempt 和 study 迁移
- 新增 MistakeReviewTest.php

接口：
- POST /api/v1/mistakes/items/{id}/review：提交答案，统一判分、写入 attempts、更新个人复习状态。
- GET /api/v1/mistakes/students/{code}/review-summary：返回新题、复习中、已掌握、暂缓、到期数量。
- GET /api/v1/mistakes/students/{code}/review-queue：支持状态、模块、错因、关键词、分页。
- PATCH /api/v1/mistakes/items/{id}/review：标记掌握、暂缓、更新个人行动建议。

固定复习间隔：
- 答错：1 天后。
- 第一次答对：3 天后。
- 连续第二次答对：7 天后。
- 连续第三次答对：14 天后并标记 mastered。
- 已掌握后再次答错：回到 reviewing，1 天后复习。

注意：mistake_items.action 只保留基础建议，个人建议进入 mistake_reviews.personal_action。

## Task 5：管理员覆盖分析文档

文件：
- 修改 AdminController、AdminService、routes
- 新增管理员资料管理页面或扩展 admin/users
- 新增 AdminMistakeProfileTest.php

接口：
- PUT /api/v1/admin/mistake-students/{code}/profile
- 支持 Markdown 文本或 .md 文件内容。
- 只有管理员可操作。
- 保存后覆盖当前分析文档并重新渲染 HTML。
- A 和私有账号都支持，权限仍按现有错题规则控制。

## Task 6：前端复习体验

文件：
- 修改 pages/mistakes/[code].vue
- 新增 pages/mistakes/[code]/review.vue
- 新增 pages/me/mistakes.vue
- 修改 types、CSS、导航和前端测试

功能：
- 错题册显示待开始、复习中、今日到期、已掌握、正确率。
- 支持状态、模块、错因和关键词筛选。
- 独立复习模式一次显示一题，提交后自动进入下一题。
- 个人中心显示错题复习统计和最近记录。
- 游客可以查看 A，但不能保存个人复习记录；登录后 A 的复习记录按用户隔离。

## Task 7：迁移、部署和文档

文件：
- 修改 development-plan.md、CHANGELOG.md、smoke 脚本
- 新增生产契约测试

验收：
- 现有管理员变为 000001。
- 现有普通用户按创建时间获得连续账号。
- 每个普通用户恰好一个账号、一个错题本、一个分析文档。
- 注册新用户自动生成下一个六位账号。
- 重新 Seeder 不删除用户错题本和复习状态。
- 游客、绑定账号、管理员三种权限均通过。
- 复习提交、统计、管理员覆盖文档均通过服务器冒烟。

开发顺序：先完成 Task 1-3 的数据基础，再完成 Task 4 的后端闭环，最后完成 Task 5-7。最终介绍文案和 skills 内容到位后，只替换默认 Markdown 内容，不需要改数据库结构。
