# 数据库迁移说明

## V0.1-dev.6 & V0.1-dev.7 迁移文件

以下迁移文件需要在 PHP 环境中执行：

```bash
cd guanlan/apps/api
php bin/hyperf.php migrate
```

## 新增迁移文件列表

### 1. 2026_09_28_000001_create_mistake_accounts_table.php
创建错题账号表，管理用户账号序列：
- 管理员固定使用 ID = 1 (000001)
- 普通用户从 ID = 2 (000002) 开始
- 自动为现有用户分配账号 ID

### 2. 2026_09_28_000002_add_owner_user_id_to_mistake_students.php
为 mistake_students 增加 owner_user_id 字段：
- 建立用户与错题本的真正绑定关系
- 自动为现有用户创建对应的错题本

### 3. 2026_09_28_000003_create_mistake_profiles_table.php
创建错题分析文件表：
- 存储每个错题本的分析文件（Markdown + HTML）
- 自动为所有错题本创建默认分析文件

### 4. 2026_09_28_000004_add_item_key_to_mistake_items.php
为 mistake_items 增加稳定标识：
- item_key: 格式为 `{studentCode}|{module}|{chapter}|{sourceNo}`
- content_hash: 题目内容版本摘要
- origin: 数据来源（dataset/admin）
- 自动为现有错题生成 item_key

### 5. 2026_09_28_000005_create_mistake_reviews_table.php
创建个人复习状态表：
- 记录每道错题的复习状态（new/reviewing/mastered/snoozed）
- 统计复习次数、正确次数、错误次数
- 记录最近一次复习和下次复习时间
- 存储个人行动建议

## 迁移后的数据结构

```
users (1) ─────── (1) mistake_accounts
                        ↓ (1)
                  mistake_students ←──┬── (1) mistake_profiles
                        ↓ (N)         │
                  mistake_items ←─────┤
                        ↑             │
                        └── (N) mistake_reviews (N) ──→ users
```

## 验证迁移

执行迁移后，可以通过以下命令验证：

```bash
# 查看新表
mysql -u root -p kaoyan_politics -e "SHOW TABLES LIKE 'mistake_%'"

# 查看 mistake_accounts 表结构
mysql -u root -p kaoyan_politics -e "DESC mistake_accounts"

# 查看 mistake_reviews 表结构
mysql -u root -p kaoyan_politics -e "DESC mistake_reviews"

# 查看现有用户的账号分配
mysql -u root -p kaoyan_politics -e "SELECT u.id, u.email, u.role, ma.id as account_id, u.mistake_code FROM users u LEFT JOIN mistake_accounts ma ON u.id = ma.user_id ORDER BY ma.id"
```

## 注意事项

1. **迁移前备份数据库**：
   ```bash
   mysqldump -u root -p kaoyan_politics > backup_$(date +%Y%m%d_%H%M%S).sql
   ```

2. **迁移顺序**：必须按文件编号顺序执行，因为存在依赖关系

3. **现有数据**：迁移会自动处理现有数据，不会丢失任何信息

4. **回滚**：如需回滚，执行：
   ```bash
   php bin/hyperf.php migrate:rollback --step=5
   ```

## 数据验证 SQL

```sql
-- 验证账号分配
SELECT 
    u.id,
    u.email,
    u.role,
    ma.id as account_id,
    u.mistake_code,
    ms.code as student_code
FROM users u
LEFT JOIN mistake_accounts ma ON u.id = ma.user_id
LEFT JOIN mistake_students ms ON ms.owner_user_id = u.id
ORDER BY ma.id;

-- 验证错题 item_key
SELECT 
    id,
    student_id,
    item_key,
    content_hash,
    origin,
    module,
    chapter,
    source_no
FROM mistake_items
LIMIT 10;

-- 验证错题分析文件
SELECT 
    mp.id,
    ms.code,
    ms.name,
    mp.is_default,
    mp.source_file,
    LENGTH(mp.markdown) as markdown_length
FROM mistake_profiles mp
JOIN mistake_students ms ON mp.student_id = ms.id;
```
