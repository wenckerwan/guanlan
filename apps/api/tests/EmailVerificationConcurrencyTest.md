# 验证码并发集成测试

## 前提与隔离

这是直接执行 AuthService 的真实 MySQL/InnoDB 多进程测试，不是 SQL 字符串检查或 mock DB。沿用既有集成测试的 `DB_DATABASE` 必须以 `_admin_test` 结尾的保护；**会清空该测试库的 email_verifications / email_verification_locks 两张表**，只能对专用可丢弃测试库执行，不能与其他测试并行。

需要 PHP 8.2+、Swoole、pdo_mysql、mbstring、proc_open，以及已安装的 API vendor 依赖。测试库需要已有项目 migrations（尤其 users 表）；测试会在缺失时单独创建两张验证码表，并检查两表均为 InnoDB。测试不加载 .env，不打印数据库凭证，不调用真实 SMTP；只有 Mailer 被替换，DB、模型、事务和服务代码均真实运行。

在 apps/api 目录，使用调用方预先注入的专用测试 DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD 环境变量运行：

    php tests/EmailVerificationConcurrencyTest.php

请勿从生产 .env 复制配置或对生产数据库运行。清理锁表仅限此测试开始于无并发活动的专用测试库；生产运行期间不可删除锁行。

## 覆盖

- 首次并发发送：锁行原先不存在，八个独立 PHP 进程同时启动，仅一个预留成功。
- 同邮箱不同 IP：小时配额临界值四条，仅能再创建一条；冷却和配额预留在同一事务。
- 同 IP 不同邮箱：小时配额临界值五十九条，仅能再创建一条，覆盖 IP 首次锁行竞争。
- 并发错误码：最终 attempts 恰为五，耗尽后正确码也被拒绝。
- 并发正确码：恰一次消费成功；最新码消费后旧的未用验证码不可重新生效。
- 过期验证码被拒绝。
- SMTP 替身断言当前事务层级为零，独立 PDO 连接可看见已提交预留；模拟发送失败仍保留配额和冷却，重试不会再次调用 SMTP。

## 本机验证限制

本次编辑环境无可用 PHP，可用 Docker 命令但 daemon 不可用（由主任务环境检查确认）。因此未执行 PHP lint 或真实数据库测试，不能将此测试报告为通过。需要在具备上述运行时与隔离数据库的 CI/开发环境补跑。没有部署、访问生产服务或读取 .env 秘密。

## 设计约束

`email_verification_locks.lock_key` 是带域前缀的 SHA-256：email/register 和 IP 分开建键。所有请求先排序锁键，再逐行 UPSERT（唯一主键解决首次并发），再 SELECT FOR UPDATE。检查窗口计数与验证码插入共处一个 `Db::transaction(..., 3)`，数据库并发异常可由 Hyperf 重试；SMTP 不在重试闭包内，提交后的发送失败不退款。锁表须先迁移再上线服务改动；常驻锁行不能在线随意清理，否则会分裂互斥。

验证也取得同邮箱锁，再 FOR UPDATE 查询最新验证码，不用 used_at 过滤。用同一行锁保护 attempts 上限、错误递增与 used_at 写入，避免丢失递增和重复消费。发新码与验旧码有确定串行顺序。邮件和注册账号事务仍独立，验证码成功消费后账号创建失败不会自动恢复验证码（保持原有单次消费语义）。
