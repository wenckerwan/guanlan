# 2026-10-22 安全检查

- 题库、排行榜、马原 content 已有登录要求，验证码 IP 已有显式可信代理处理。
- HistoryService 只筛数据集 status，直接返回包含 34 条 pending comparisons 的 payload。前端隐藏不构成服务器权限控制。
- VisitController 接受任意 64 hex；需使用 APP_KEY 做带用途隔离的 HMAC 并验证。
- AuthService 在邮件不可用时跳过验证码直接创建账号，应关闭自助注册并返回 503。
- 网关当前覆盖 XFF，但没有限流；主站可在现有可信来源策略上按 binary_remote_addr 限制登录与访问写入。
- 马原已有 host-only cookie 配置与 SecureCookieMiddleware；未确认报告的空 body 问题，不能猜测改动。
- 官方 Node 22.23.4、nginx 1.30.6 安全版本已核验。独立 SillyTavern/New API/宿主 OpenResty 不在本仓库部署范围。
