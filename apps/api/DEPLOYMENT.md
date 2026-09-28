# 部署和迁移完整指南

## 当前状态

✅ **代码已完成**：
- V0.1-dev.6（错题底层重构）
- V0.1-dev.7（复习功能 + AI 分析对接）

⏳ **等待执行**：
- Composer 依赖安装
- 数据库迁移

## 环境要求

### 必需软件

- ✅ PHP 8.3.35（已找到：`C:\Users\Administrator\AppData\Local\Temp\php-lint\php.exe`）
- ❌ Composer（需要安装）
- ❓ MySQL/MariaDB（需要确认是否运行）

## 完整部署步骤

### 第 1 步：安装 Composer

#### 方式一：下载安装器（推荐）

```bash
# 下载 Composer 安装器
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"

# 验证安装器（可选）
php -r "if (hash_file('sha384', 'composer-setup.php') === 'dac665fdc30fdd8ec78b38b9800061b4150413ff2e3b6f88543c636f7cd84f6db9189d43a81e5503cda447da73c7e5b6') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"

# 安装 Composer
php composer-setup.php

# 清理安装器
php -r "unlink('composer-setup.php');"

# 移动到全局位置（可选）
mv composer.phar /usr/local/bin/composer
```

#### 方式二：使用现有项目的 Composer

如果项目中已经有 `composer.phar`：

```bash
cd guanlan/apps/api
php composer.phar install
```

### 第 2 步：安装项目依赖

```bash
cd guanlan/apps/api

# 安装依赖（可能需要几分钟）
composer install

# 或使用下载的 composer.phar
php /path/to/composer.phar install
```

预期输出：
```
Loading composer repositories with package information
Installing dependencies from lock file
...
Generating autoload files
```

### 第 3 步：配置环境变量

```bash
cd guanlan/apps/api

# 复制环境配置模板
cp .env.example .env

# 编辑 .env 文件，配置数据库连接
nano .env  # 或使用其他编辑器
```

必需配置项：
```env
APP_NAME=观澜考研政治
APP_ENV=local

DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=kaoyan_politics
DB_USERNAME=root
DB_PASSWORD=your_password
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
DB_PREFIX=

# 管理员初始凭据（用于 Seeder）
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=Admin@123456
ADMIN_DISPLAY_NAME=管理员
```

### 第 4 步：验证数据库连接

```bash
# 测试 MySQL 连接
mysql -u root -p -e "SELECT VERSION();"

# 创建数据库（如果不存在）
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS kaoyan_politics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 验证数据库已创建
mysql -u root -p -e "SHOW DATABASES LIKE 'kaoyan_politics';"
```

### 第 5 步：执行数据库迁移

#### 5.1 备份现有数据（如果有）

```bash
mysqldump -u root -p kaoyan_politics > backup_$(date +%Y%m%d_%H%M%S).sql
```

#### 5.2 执行迁移

```bash
cd guanlan/apps/api

# 使用找到的 PHP 可执行文件
/c/Users/Administrator/AppData/Local/Temp/php-lint/php.exe bin/hyperf.php migrate

# 或使用系统 PHP（如果在 PATH 中）
php bin/hyperf.php migrate
```

预期输出：
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

### 第 6 步：验证迁移成功

```bash
# 查看所有表
mysql -u root -p kaoyan_politics -e "SHOW TABLES;"

# 验证新表已创建
mysql -u root -p kaoyan_politics -e "SHOW TABLES LIKE 'mistake_%';"

# 查看 mistake_accounts 表结构
mysql -u root -p kaoyan_politics -e "DESC mistake_accounts;"

# 查看 mistake_reviews 表结构
mysql -u root -p kaoyan_politics -e "DESC mistake_reviews;"

# 查看 mistake_items 新增字段
mysql -u root -p kaoyan_politics -e "DESC mistake_items;" | grep -E "item_key|content_hash|origin"
```

### 第 7 步：运行 Seeder（可选但推荐）

```bash
cd guanlan/apps/api

# 导入基础数据
php bin/hyperf.php db:seed --class=SubjectSeeder
php bin/hyperf.php db:seed --class=DocumentSeeder
php bin/hyperf.php db:seed --class=ArticleSeeder
php bin/hyperf.php db:seed --class=PaperQuestionSeeder
php bin/hyperf.php db:seed --class=MistakeSeeder

# 或一次性运行所有 Seeder
php bin/hyperf.php db:seed
```

### 第 8 步：启动服务

```bash
cd guanlan/apps/api

# 启动开发服务器
php bin/hyperf.php start

# 验证服务运行
curl http://localhost:9501/api/v1/health
```

### 第 9 步：测试 API 功能

#### 测试复习 API

```bash
# 获取复习概览（需要先登录获取 token）
curl http://localhost:9501/api/v1/mistakes/students/A/review-summary \
  -H "Authorization: Bearer your-jwt-token"

# 获取 AI 配置模板
curl http://localhost:9501/api/v1/mistakes/ai-config
```

#### 测试 AI 分析

```bash
cd guanlan/apps/api

# 使用测试脚本
php test-ai-analysis.php openai sk-your-api-key

# 或使用 API 接口
curl -X POST http://localhost:9501/api/v1/mistakes/students/A/ai-analysis \
  -H "Authorization: Bearer your-jwt-token" \
  -H "Content-Type: application/json" \
  -d '{
    "provider": "openai",
    "apiKey": "sk-your-api-key",
    "model": "gpt-4"
  }'
```

## 快速命令摘要

```bash
# 1. 安装 Composer（如果未安装）
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"

# 2. 安装依赖
cd guanlan/apps/api
composer install

# 3. 配置环境
cp .env.example .env
# 编辑 .env 文件

# 4. 创建数据库
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS kaoyan_politics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 5. 备份（如果有现有数据）
mysqldump -u root -p kaoyan_politics > backup_$(date +%Y%m%d_%H%M%S).sql

# 6. 执行迁移
php bin/hyperf.php migrate

# 7. 运行 Seeder
php bin/hyperf.php db:seed

# 8. 启动服务
php bin/hyperf.php start

# 9. 测试 AI 分析
php test-ai-analysis.php openai sk-your-api-key
```

## 故障排查

### 问题 1：Composer 安装失败

**错误**：`curl: (7) Failed to connect`

**解决**：
1. 检查网络连接
2. 尝试使用代理
3. 手动下载 Composer：https://getcomposer.org/download/

### 问题 2：依赖安装失败

**错误**：`Your requirements could not be resolved`

**解决**：
```bash
# 清除缓存
composer clear-cache

# 更新 Composer 自身
composer self-update

# 重新安装
composer install --no-cache
```

### 问题 3：数据库连接失败

**错误**：`SQLSTATE[HY000] [2002] Connection refused`

**解决**：
1. 确认 MySQL 服务运行：`systemctl status mysql`
2. 检查 .env 配置是否正确
3. 验证用户权限：`GRANT ALL PRIVILEGES ON kaoyan_politics.* TO 'root'@'localhost';`

### 问题 4：迁移执行失败

**错误**：`SQLSTATE[42S01]: Base table or view already exists`

**解决**：
```bash
# 回滚之前的迁移
php bin/hyperf.php migrate:rollback

# 重新执行
php bin/hyperf.php migrate
```

### 问题 5：PHP 扩展缺失

**错误**：`ext-pdo_mysql is missing`

**解决**：
```bash
# 安装 PHP MySQL 扩展
# Ubuntu/Debian
sudo apt-get install php8.3-mysql

# CentOS/RHEL
sudo yum install php83-mysqlnd

# 编辑 php.ini 启用扩展
extension=pdo_mysql
```

## 生产环境部署注意事项

1. **更改管理员密码**：部署后立即更改默认管理员密码
2. **配置 HTTPS**：使用 Nginx/Apache 配置 SSL 证书
3. **设置进程管理**：使用 Supervisor 或 systemd 管理服务
4. **配置日志**：设置日志轮转和监控
5. **定期备份**：建立自动化数据库备份机制
6. **监控告警**：配置服务监控和异常告警

## 相关文档

- [MIGRATION.md](guanlan/MIGRATION.md) - 数据库迁移详细说明
- [MIGRATION-GUIDE.md](MIGRATION-GUIDE.md) - 迁移执行指南
- [AI-ANALYSIS-TEST.md](guanlan/docs/AI-ANALYSIS-TEST.md) - AI 分析测试文档
- [README.md](guanlan/README.md) - 项目说明

## 下一步

迁移完成后，你可以：

1. ✅ 开始使用错题复习功能
2. ✅ 配置 AI 分析 API
3. ✅ 测试完整的错题分析流程
4. ✅ 开发前端复习页面
5. ✅ 提交代码到 Git 仓库

---

**创建时间**：2026-09-28  
**版本**：V0.1-dev.6 & V0.1-dev.7  
**状态**：待执行
