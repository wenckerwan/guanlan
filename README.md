# 观澜｜考研政治知识库

一个为考研政治学习设计的内容聚合平台。整合教材知识点、历年真题、时政热点与个人错题，提供在线刷题、进度追踪与错题重练的完整学习闭环。

## 这是什么

**面向考研党的政治复习工具**，把散落在教材、真题、时政资料中的内容整合成结构化的在线知识库，支持按章节刷题、错题自动归类、时政热点追踪与个人学习数据分析。

**核心特性**

| 特性 | 说明 |
|------|------|
| 📚 真题回顾 | 42 套历年真题 / 1479 题，支持隐藏答案先做题 |
| 📊 真题分析 | 13 篇深度解析，梳理命题规律 |
| 🔥 时政热点 | 持续追踪，3 期热点 + 6 篇预测 |
| 📝 模拟押题 | 高频考点模拟卷 |
| 🎯 错题重练 | 自动归类、原错因对比、行动建议追踪 |
| 🔍 全站搜索 | 一次聚合七类内容 |

**技术栈**

- **后端**: PHP 8.3 + Hyperf (Swoole) — 高性能 API
- **前端**: Nuxt 3 + Vue 3 — 现代前端体验
- **数据库**: MySQL 8.4 + Redis — 持久层
- **部署**: Docker Compose 全容器化，宝塔反向代理
- **离线工具**: Python 数据导入/校验器

## 快速开始

### 在线部署（宝塔 + Docker）

```bash
# 1. 克隆代码
git clone https://github.com/wenckerwan/guanlan.git
cd guanlan

# 2. 配置环境变量
cp .env.production.example .env.production
# 生成随机密钥后填入，chmod 600 .env.production

# 3. 一键部署
./tools/deploy/deploy.sh .env.production
```

详见 [docs/deployment.md](docs/deployment.md)。

### 本地开发

```bash
# 前端
cd apps/web && npm install && npm run dev

# 离线数据工具
python tools/ingest/build_all.py

# 离线验证（无需 PHP/Docker）
powershell -ExecutionPolicy Bypass -File tools/verify.ps1
```

## 数据与内容

所有内容来自 `F:\2027考研资料\考研政治` 离线源目录，经 `tools/ingest/` 清洗、去重后导入 MySQL：

| 模块 | 规模 |
|------|------|
| 真题回顾 | 42 卷 / 1479 题 |
| 真题分析 | 13 篇 |
| 时政热点 | 3 期 |
| 时政预测 | 6 篇 |
| 模拟押题 | 1 套 / 38 题 |
| 错题分析 | 2 名考生 / 292 题 / 5 份手册 |

**数据完整性保障**: 每个数据集附带 SHA-256 校验摘要，API 启动前自动校验，防止文件被篡改。

## API 和用户系统

- **内容 API**: 52 个路由，统一 `{"data": ...}` 包络
- **认证**: 注册/登录 / Token (sha256 存储) / 权限
- **用户态**: 收藏 / 笔记 / 做题记录 / 阅读进度 / 正确率统计
- **后台管理**: 内容总览、时政热点与真题分析的增删改

详见 [docs/api.md](docs/api.md)。

## 部署架构

应用全容器化，宝塔仅负责域名证书与反向代理：

```
宝塔 Nginx (443/80) → http://127.0.0.1:8080
                    → Docker Nginx (gateway)
                        → Web (Nuxt:3000)
                        → API (Hyperf:9501)
                        → MySQL (内部)
                        → Redis (内部)
```

仅 `127.0.0.1:8080` 对外暴露，MySQL/Redis 内部网络，密钥不入 Git，日志轮转 10MB×3。

详见 [docs/deployment.md](docs/deployment.md) 与 [docs/production-acceptance-checklist.md](docs/production-acceptance-checklist.md)。

## 开发计划

- [docs/development-plan.md](docs/development-plan.md) — 当前阻塞项与验收标准
- [docs/website-roadmap.md](docs/website-roadmap.md) — 内容来源映射与数据流
- [CHANGELOG.md](CHANGELOG.md) — 版本变更与验证记录

## License

私有学习项目，未授权请勿商业使用。

---

**版本**: `V0.1-dev.5` | **状态**: 生产就绪（待服务器验证） | **最后更新**: 2026-09-27
