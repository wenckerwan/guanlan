# 504 错误诊断指南

## 问题现象
- 网站无法访问
- Nginx 返回：504 Gateway Time-out

## 常见原因

504 错误表示 Nginx 无法在规定时间内从后端服务获取响应。可能的原因：

1. **后端服务未启动或已崩溃**
2. **后端服务响应过慢**
3. **端口配置错误**
4. **网络连接问题**
5. **资源耗尽（内存/CPU）**

## 诊断步骤

### 1. 检查容器状态（服务器上执行）

```bash
# 查看所有容器状态
docker-compose ps

# 或
docker ps -a | grep guanlan
```

**期望输出**：所有服务应该是 `Up` 状态

### 2. 查看 API 容器日志

```bash
# 查看最近的错误日志
docker-compose logs --tail=100 api

# 实时监控日志
docker-compose logs -f api
```

**关注点**：
- 启动错误
- Fatal errors
- PHP Fatal error
- Database connection errors
- Memory exhausted

### 3. 检查服务端口

```bash
# 检查 API 服务是否在监听
docker-compose exec api netstat -tuln | grep 9501

# 检查 Nginx 网关
docker-compose exec gateway netstat -tuln | grep 80
```

### 4. 测试容器内部连接

```bash
# 从 gateway 容器测试到 API
docker-compose exec gateway curl -v http://api:9501/api/v1/health

# 或直接从宿主机测试
curl -v http://localhost:9501/api/v1/health
```

### 5. 检查系统资源

```bash
# 查看内存使用
free -h

# 查看 CPU 使用
top -bn1 | head -20

# 查看磁盘空间
df -h
```

## 快速修复方案

### 方案 1: 重启服务

```bash
# 重启所有服务
docker-compose restart

# 或只重启 API
docker-compose restart api
```

### 方案 2: 完全重建

```bash
# 停止所有服务
docker-compose down

# 重新启动
docker-compose up -d

# 查看启动日志
docker-compose logs -f
```

### 方案 3: 检查配置文件

```bash
# 检查 Nginx 配置
docker-compose exec gateway nginx -t

# 查看反向代理配置
docker-compose exec gateway cat /etc/nginx/conf.d/default.conf
```

## 常见问题解决

### API 服务启动失败

**症状**：`docker-compose ps` 显示 api 容器 Exit 状态

**原因**：
- 数据库连接失败
- PHP 语法错误
- 依赖缺失

**解决**：
```bash
# 查看启动错误
docker-compose logs api | tail -50

# 进入容器手动启动查看详细错误
docker-compose exec api bash
php bin/hyperf.php start
```

### 数据库连接超时

**症状**：日志显示 "SQLSTATE[HY000] [2002] Connection timed out"

**解决**：
```bash
# 检查 MySQL 容器状态
docker-compose ps mysql

# 重启 MySQL
docker-compose restart mysql

# 等待 10 秒后重启 API
sleep 10 && docker-compose restart api
```

### 内存不足

**症状**：日志显示 "Allowed memory size exhausted"

**解决**：
```bash
# 临时增加 PHP 内存限制
docker-compose exec api bash
echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/memory.ini
exit

# 重启 API
docker-compose restart api
```

### Nginx 超时设置

如果 API 响应慢但未崩溃，可以增加 Nginx 超时时间：

```nginx
# 编辑 docker/gateway/nginx.conf
location /api/ {
    proxy_pass http://api:9501;
    proxy_read_timeout 300s;  # 增加到 5 分钟
    proxy_connect_timeout 300s;
}
```

然后重启：
```bash
docker-compose restart gateway
```

## 预防措施

1. **定期查看日志**：`docker-compose logs -f`
2. **监控资源使用**：设置告警
3. **定期备份数据库**
4. **保持日志轮转**：避免磁盘满
5. **配置健康检查**：自动重启异常容器

## 联系我

如果以上方案都无法解决，请提供：
1. `docker-compose ps` 的输出
2. `docker-compose logs api --tail=100` 的输出
3. 服务器系统资源情况（`free -h` 和 `df -h`）
