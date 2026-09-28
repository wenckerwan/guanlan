# 数据库迁移执行指南

## 当前状态

- ✅ 迁移文件已创建（5 个）
- ✅ 测试脚本已准备
- ⏳ 等待在 PHP 环境中执行

## 迁移文件列表

所有迁移文件位于 `apps/api/migrations/` 目录：

```
2026_09_28_000001_create_mistake_accounts_table.php
2026_09_28_000002_add_owner_user_id_to_mistake_students.php
2026_09_28_000003_create_mistake_profiles_table.php
2026_09_28_000004_add_item_key_to_mistake_items.php
2026_09_28_000005_create_mistake_reviews_table.php
```

## 执行方法

### 方法一：本地 PHP 环境（推荐）

如果你本地安装了 PHP，执行：

```bash
cd apps/api
php bin/hyperf.php migrate
```

### 方法二：Docker 容器

如果使用 Docker 开发环境：

```bash
# 启动容器
docker-compose up -d

# 进入 API 容器
docker-compose exec api bash

# 执行迁移
php bin/hyperf.php migrate
```

### 方法三：远程服务器

如果在远程服务器上部署：

```bash
# SSH 连接到服务器
ssh user@your-server

# 进入项目目录
cd /path/to/guanlan/apps/api

# 执行迁移
php bin/hyperf.php migrate
```

## 迁移前检查清单

- [ ] 已备份数据库
- [ ] 已检查 .env 配置
- [ ] 数据库连接正常
- [ ] 有足够的权限创建表和字段

## 备份命令

在执行迁移前，强烈建议备份数据库：

```bash
# MySQL/MariaDB
mysqldump -u root -p kaoyan_politics > backup_$(date +%Y%m%d_%H%M%S).sql

# 或通过 Docker
docker-compose exec mysql mysqldump -u root -p kaoyan_politics > backup_$(date +%Y%m%d_%H%M%S).sql
```

## 验证迁移成功

执行迁移后，运行以下命令验证：

```bash
# 查看新表
mysql -u root -p kaoyan_politics -e "SHOW TABLES LIKE 'mistake_%'"

# 查看 mistake_accounts 表结构
mysql -u root -p kaoyan_politics -e "DESC mistake_accounts"

# 查看 mistake_reviews 表结构
mysql -u root -p kaoyan_politics -e "DESC mistake_reviews"

# 查看 mistake_items 新字段
mysql -u root -p kaoyan_politics -e "DESC mistake_items" | grep -E "item_key|content_hash|origin"

# 查看现有用户的账号分配
mysql -u root -p kaoyan_politics -e "SELECT u.id, u.email, u.role, ma.id as account_id, u.mistake_code FROM users u LEFT JOIN mistake_accounts ma ON u.id = ma.user_id ORDER BY ma.id"
```

## 预期结果

迁移成功后，你应该看到：

1. **新表创建**：
   - `mistake_accounts` - 账号序列表
   - `mistake_profiles` - 错题分析文件表
   - `mistake_reviews` - 个人复习状态表

2. **新字段添加**：
   - `mistake_students.owner_user_id` - 用户绑定
   - `mistake_items.item_key` - 稳定标识
   - `mistake_items.content_hash` - 内容版本
   - `mistake_items.origin` - 数据来源

3. **数据迁移**：
   - 现有用户已分配账号 ID
   - 现有用户已创建对应错题本
   - 所有错题本已创建默认分析文件
   - 所有错题已生成 item_key

## 迁移输出示例

成功的迁移应该显示类似以下内容：

```
Migrating: 2026_09_28_000001_create_mistake_accounts_table
Migrated:  2026_09_28_000001_create_mistake_accounts_table (123.45ms)

Migrating: 2026_09_28_000002_add_owner_user_id_to_mistake_students
Migrated:  2026_09_28_000002_add_owner_user_id_to_mistake_students (234.56ms)

Migrating: 2026_09_28_000003_create_mistake_profiles_table
Migrated:  2026_09_28_000003_create_mistake_profiles_table (345.67ms)

Migrating: 2026_09_28_000004_add_item_key_to_mistake_items
Migrated:  2026_09_28_000004_add_item_key_to_mistake_items (456.78ms)

Migrating: 2026_09_28_000005_create_mistake_reviews_table
Migrated:  2026_09_28_000005_create_mistake_reviews_table (567.89ms)
```

## 回滚（如需要）

如果迁移出现问题，可以回滚：

```bash
# 回滚最近的 5 个迁移
php bin/hyperf.php migrate:rollback --step=5
```

## 常见问题

### Q1: 提示 "SQLSTATE[42S01]: Base table or view already exists"

**原因**：表已存在

**解决**：检查是否已经执行过迁移，或手动删除冲突的表后重新执行

### Q2: 提示 "Access denied for user"

**原因**：数据库权限不足

**解决**：确保 .env 中的数据库用户有 CREATE、ALTER 权限

### Q3: 迁移执行很慢

**原因**：数据量大，正在为现有数据生成 item_key

**解决**：正常现象，耐心等待。可以在另一个终端查看数据库进程：
```bash
mysql -u root -p -e "SHOW PROCESSLIST"
```

## 下一步

迁移成功后，你可以：

1. **测试 API 接口**
   ```bash
   # 启动服务
   php bin/hyperf.php start
   
   # 测试复习概览接口
   curl http://localhost:9501/api/v1/mistakes/students/A/review-summary
   ```

2. **测试 AI 分析**
   ```bash
   php test-ai-analysis.php openai sk-your-api-key
   ```

3. **运行 Seeder 验证幂等导入**
   ```bash
   php bin/hyperf.php db:seed --class=MistakeSeeder
   ```

4. **提交代码到 Git**
   ```bash
   git add .
   git commit -m "feat: 错题底层重构与复习功能"
   git push
   ```

## 技术支持

如果遇到问题，可以：

1. 查看详细的迁移文档：[MIGRATION.md](../../MIGRATION.md)
2. 查看迁移文件源码了解具体操作
3. 运行单元测试验证功能：`./vendor/bin/phpunit`

---

**重要提醒**：
- 在生产环境执行迁移前，务必先在测试环境验证
- 迁移过程中不要中断，否则可能导致数据不一致
- 保留好备份文件，至少保留 7 天
