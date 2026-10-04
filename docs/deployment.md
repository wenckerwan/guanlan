# 部署说明与当前验证边界

## 本地开发

`npm run dev` 启动 Nuxt4179 和 PHP8086，显式使用开发身份及SQLite。仅监听本机回环地址，不能把这一启动方式当作公开正式部署。

## Hyperf/MySQL部署目标

根目录提供 `compose.yml`、前端镜像与 API 镜像。生产使用MySQL、关闭开发登录，并要求可信观澜身份。先复制 `.env.production.example` 为 `.env`，填写独立强密码、浏览器准确HTTPS来源和观澜地址；不要提交 `.env`。

```sh
docker compose --env-file .env config
docker compose --env-file .env up -d --build
```

Web只映射到宿主回环地址4180，需另行配置HTTPS反向代理。MySQL和API不对宿主公网映射。页面Origin必须与 `MAYUAN_ORIGINS` 精确匹配。

正式会话使用Secure Cookie。不要在普通HTTP公网地址上关闭它来规避登录问题；配置正确的HTTPS入口。

`NUXT_API_BASE` 为Nuxt服务端访问PHP的地址，不能当作浏览器公开API地址。浏览器通过同源 `/api/v2` 代理访问。

## 发布前需要实测

- 镜像构建、Hyperf启动与MySQL初始化。
- 全部HTTP安全与学习接口在Hyperf下的行为，特别是会话和并发用户隔离。
- HTTPS代理、Cookie、深链接、资源路径和允许来源。
- 数据库卷备份、恢复、更新和内容版本回退。
- 真实观澜身份校验；宿主适配完成后的登录交接、退出与摘要同步。

当前开发主机无Docker，本说明和配置属于部署目标，不能据此声称上述容器验收已通过。正式观澜接口尚未完成时，应用应明确显示未接入，不能自动开启开发账号补位。

## 摘要发送

摘要事件随学习记录事务持久化。默认不向任何宿主发送；宿主协议可用并联调后，显式填写 `GUANLAN_SUMMARY_PROTOCOL=mayuan-summary-v1`、HTTPS `GUANLAN_SUMMARY_URL` 和受限 `GUANLAN_SERVICE_TOKEN`。

通过 `docker compose exec api php bin/outbox.php` 执行一次队列发送。生产运维可在联调验收后安排周期执行，当前配置不自动启用调度。重试、确认版本与身份映射协议见 `apps/api/README.md`。

前端身份登录交接与后台摘要发送是不同能力。配置摘要地址不代表单点登录已经实现。
