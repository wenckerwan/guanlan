# 错题功能部署检查清单

> ⚠️ **重要（2026-09-28 更新）**：本文部分旧步骤使用了 `docker-compose.yml`（开发配置）和错误路径 `/root/guanlan`。
> 生产服务器**必须**统一使用 `compose.production.yml`，项目实际路径为 `/www/wwwroot/guanlan`。
> **正确、完整的生产部署与 502/404 排障流程，请见 [`TROUBLESHOOTING-502.md`](./TROUBLESHOOTING-502.md)。**
> 标准命令：`dcprod up -d --build`（别名 `dcprod='docker compose -f compose.production.yml --env-file .env.production'`）。

## 📋 部署前检查

### 代码检查
- [x] 错题列表页面优化完成
- [x] 错题详情页面集成复习功能
- [x] 复习概览页面创建完成
- [x] Git 暂存的代码已恢复
- [ ] 代码已提交到 Git

### 服务端检查
- [x] 504 错误已修复
- [x] 错题模块依赖已修复
- [x] API 服务运行正常
- [ ] 数据库迁移已执行（如需要）

## 🚀 部署步骤

### 1. 本地提交代码

```bash
cd guanlan
git status
git add apps/web/pages/mistakes/
git commit -m "feat: 优化错题功能，集成复习系统

- 移除废弃的账号 ID 绑定提示
- 集成间隔重复复习 API
- 新增复习概览页面
- 优化登录引导流程
- 改进错误处理和降级逻辑

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### 2. 推送到远程仓库

```bash
git push origin v0.1-dev.7
```

### 3. 服务器更新代码

```bash
ssh root-189 "cd /www/wwwroot/guanlan && git pull origin v0.1-dev.7"
```

### 4. 用生产配置重建并启动（⚠️ 已废弃旧的 `docker-compose.yml restart` 写法）

```bash
# 生产 web 是构建时打包代码进镜像，必须 --build 才能带上新代码
ssh root-189 "cd /www/wwwroot/guanlan && docker compose -f compose.production.yml --env-file .env.production up -d --build"
```

> 💡 若部署后整站 502，多为 gateway 缓存了 web 旧 IP：
> `ssh root-189 "cd /www/wwwroot/guanlan && docker compose -f compose.production.yml --env-file .env.production up -d --force-recreate gateway"`

### 5. 验证部署（等待容器启动）

```bash
# 等待 30 秒
sleep 30

# 检查容器状态
ssh root-189 "docker ps --filter 'name=guanlan-web' --format 'table {{.Names}}\t{{.Status}}'"

# 测试前端访问
ssh root-189 "curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/mistakes"
```

## ✅ 部署后验证

### 功能测试

1. **错题列表页面**
   - [ ] 访问 `/mistakes` 页面正常加载
   - [ ] 未登录时显示登录引导
   - [ ] 登录后显示错题本列表
   - [ ] 登录弹窗文案正确（自动创建，不提账号 ID）

2. **错题详情页面**
   - [ ] 访问 `/mistakes/A` 页面正常加载（替换为实际代码）
   - [ ] 显示「复习概览」按钮（登录时）
   - [ ] 重做题目功能正常
   - [ ] 提交答案后显示复习信息

3. **复习概览页面**
   - [ ] 访问 `/mistakes/A/review` 页面正常加载
   - [ ] 显示复习统计数据
   - [ ] 显示进度分布
   - [ ] 显示间隔规则说明

### 性能检查

- [ ] 页面加载速度正常（< 2s）
- [ ] 无 JavaScript 错误（查看浏览器控制台）
- [ ] 移动端布局正常
- [ ] 响应式设计适配良好

### API 检查

```bash
# 1. 测试错题列表 API
ssh root-189 "curl -s http://localhost:8080/api/v1/mistakes/students"

# 2. 测试复习概览 API（需要有效的 JWT token）
# ssh root-189 "curl -s -H 'Authorization: Bearer <token>' http://localhost:8080/api/v1/mistakes/students/A/review-summary"

# 3. 检查 API 日志
ssh root-189 "docker logs --tail=50 guanlan-api-1 | grep -E 'ERROR|Exception'"
```

## 🔧 故障排查

### 问题：页面 404
**原因**: Nuxt 路由未正确识别  
**解决**: 重启 web 容器
```bash
ssh root-189 "docker-compose restart web"
```

### 问题：复习 API 返回 500
**原因**: 数据库表未创建  
**解决**: 执行数据库迁移
```bash
ssh root-189 "docker exec guanlan-api-1 php bin/hyperf.php migrate"
```

### 问题：样式显示异常
**原因**: CSS 缓存  
**解决**: 清除浏览器缓存或强制刷新（Ctrl+Shift+R）

### 问题：登录后仍无错题本
**原因**: 注册流程未触发错题本创建  
**解决**: 
1. 检查 API 日志
2. 手动调用 provision 接口
3. 联系管理员检查数据库

## 📝 部署记录

- **部署日期**: _______________
- **部署人员**: _______________
- **Git Commit**: _______________
- **部署结果**: [ ] 成功 [ ] 失败
- **问题记录**: _______________

## 🎯 后续任务

- [ ] 监控错误日志 24 小时
- [ ] 收集用户反馈
- [ ] 调整复习间隔参数（如需要）
- [ ] 准备下一版本优化计划

---

**检查清单版本**: v1.0  
**创建时间**: 2026-09-28
