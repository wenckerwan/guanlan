# 生产环境 502 / 路由 404 / 500 排障指南

> 本文记录 2026-09-28 两次事故的**真实根因**与正确运维流程：
> ①「AI 错题分析页上线失败」（502/404）；②「登录接口持续 500」（PHP 反斜杠被剥离）。
> 此前两轮错误诊断（「顶层 await 导致路由不注册」「`[code].vue` 与 `[code]/` 目录冲突」）均不成立，特此澄清，避免再次被误导。

---

## 一、事故现象

- 新增的 AI 错题分析页 `/mistakes/analyze/A` 访问失败。
- 反复「修路由、移文件、清缓存、重启容器」均无效。
- 表象在 **404 Not Found** 与 **502 Bad Gateway** 之间变化，极具迷惑性。

---

## 二、真实根因（两层，环环相扣）

### 根因 1：gateway(nginx) 缓存了 upstream web 的陈旧 IP

- gateway 用的是 `docker/nginx/production.conf`，其中 `location / { proxy_pass http://web:3000; }`。
- **nginx 只在启动时把 upstream 主机名 `web` 解析一次并缓存**。
- 当时 gateway 启动时把 `web` 解析成了旧 IP `172.21.0.5`；但 web 容器后来重建，真实 IP 变成 `172.21.0.7`。
- 结果：nginx 持续向 `172.21.0.5:3000` 发请求 → `connect() failed (111: Connection refused)` → **502**。

gateway 日志铁证：

```
connect() failed (111: Connection refused) while connecting to upstream,
upstream: "http://172.21.0.5:3000/mistakes/analyze/A"
```

> 关键验证：**在 web 容器内部** `wget http://127.0.0.1:3000/mistakes/analyze/A` 能正常返回
> `<title>AI 错题分析 - A ｜观澜</title>`——**路由从头到尾就没坏过**，坏的是网关转发。

### 根因 2：两套 compose 文件被混用

用容器 label 可查到来源（`docker inspect <容器> --format '{{json .Config.Labels}}'`）：

| 容器 | 来源配置文件 | 性质 |
|---|---|---|
| `guanlan-web-1` | `docker-compose.yml` | **开发**（裸 `node:22-alpine`，启动时才 `npm run build`，无 healthcheck） |
| `guanlan-gateway-1` | `compose.production.yml` | **生产**（nginx 网关 + healthcheck） |

- 交替执行 `docker-compose ...` 和 `docker compose -f compose.production.yml ...`，把两套同时拉起。
- dev web 每次重建就在同一网络 `guanlan_default` 里换 IP，而生产 gateway 死死缓存旧地址 → 必然 502。

---

## 三、被证伪的两个错误诊断

| 错误说法 | 真相 |
|---|---|
| 「页面里顶层 `await` 导致 Nuxt 路由无法注册」 | Nuxt 3 的 `<script setup>` **本身就是 async setup**，顶层 `await` 合法且常用，不会导致 404。 |
| 「`mistakes/[code].vue` 与 `mistakes/[code]/` 目录不能在 Nuxt 中共存」 | Nuxt 3 文件路由把 `[code].vue` 视为 `[code]/index.vue`，与同名目录下的子页面（`review.vue`、`[id].vue`、`analyze/[code].vue`）**完全共存**。本项目 `review.vue` 一直是这么正常工作的。 |

> 结论：当年所谓「方案 B 移动 `analyze/[code].vue` 到一个新目录」是**多余操作**，并未触及根因。

---

## 四、本次修复步骤（已验证 ✅）

```bash
# 进入服务器目录
cd /www/wwwroot/guanlan

# 1. 移除游离的开发版 web 容器
docker rm -f guanlan-web-1

# 2. 用生产配置重建镜像 + 启动 web / gateway
docker compose -f compose.production.yml --env-file .env.production up -d --build web gateway

# 3. 关键一步：强制重建 gateway，让 nginx 重新解析 web 的当前 IP
docker compose -f compose.production.yml --env-file .env.production up -d --force-recreate gateway

# 4. 验证
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/mistakes/analyze/A   # 期望 200
```

---

## 五、正确部署流程（以后一律照此执行）

> 服务器上只能有**一套** compose 生效：**`compose.production.yml`**。
> `docker-compose.yml` 仅供**本地开发**，**禁止**在生产服务器使用。

### 标准命令

```bash
cd /www/wwwroot/guanlan
git pull                                    # 或 git pull origin <分支>
docker compose -f compose.production.yml --env-file .env.production up -d --build
```

> ⚠️ 生产 web 是**构建时把代码 COPY 进镜像**（`apps/web/Dockerfile`：`COPY . . && npm run build`，仅拷贝 `.output`）。
> 仅 `git pull` + `restart` **不会**把新代码烘进镜像，**必须 `--build`**（或代码无变化时 `up -d` 也会因配置变化重建）。

### 建议：设置别名 `dcprod`，一劳永逸防止混用

```bash
echo "alias dcprod='docker compose -f compose.production.yml --env-file .env.production'" >> ~/.bashrc
source ~/.bashrc

# 之后部署只需：
dcprod up -d --build
```

---

## 六、快速对症速查

| 现象 | 大概率原因 | 一条命令解决 |
|---|---|---|
| web 重建后整站 502 | gateway nginx 缓存了 web 旧 IP | `dcprod up -d --force-recreate gateway` |
| 新页面上线后 404 | 镜像是旧代码构建的（缺 `--build`） | `dcprod up -d --build` |
| gateway 状态 `unhealthy` | 健康检查 `wget 127.0.0.1` 经自身代理又 502（死循环） | 先修上面两条，再 `--force-recreate gateway` |
| 不确定容器来自哪套配置 | —— | `docker inspect <容器> --format '{{index .Config.Labels "com.docker.compose.project.config_files"}}'` |
| 怀疑路由没注册，先自证清白 | —— | `docker exec guanlan-web-1 wget -q -O- http://127.0.0.1:3000/<路由> \| head` |
| **API 持续 500 + 登录/注册失败** | PHP 文件头部反斜杠被剥离（见第九节） | `docker logs guanlan-api-1 --tail 30 \| grep -i fatal` 看是否 `Cannot declare class` |

---

## 七、部署后验证清单

```bash
for p in / /mistakes /mistakes/A /mistakes/A/review /mistakes/analyze/A; do
  printf '%-24s ' "$p"
  curl -s -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:8080${p}"
done
# 全部应为 200
```

```bash
# 6 个容器应全部 healthy：api / gateway / web / mysql / redis / meilisearch
docker compose -f compose.production.yml --env-file .env.production ps
```

---

## 八、案例二：登录接口持续 500（PHP 反斜杠被剥离）

> 这是同一天紧随其后的第二个坑，根因完全不同，但迷惑性极强——**代码 git 历史里每个版本都是坏的**。

### 现象

- 网站能打开（前端路由 200），但**登录/注册接口一直返回 500**。
- `docker ps` 里 api 容器显示 `healthy`，极具欺骗性——healthcheck 只探了端口活着，没探业务。

### 根因：某次写入时 PHP **反斜杠 `\` 被当转义符吃掉**

api 容器日志铁证（`docker logs guanlan-api-1 --tail 30`）：

```
Fatal error: Cannot declare class AppService\AuthService,
because the name is already in use in .../src/Service/AuthService.php on line 11
```

打开文件一看，**头部命名空间的反斜杠全被剥掉了**：

```php
// ❌ 损坏（无层级的「伪命名空间」）
namespace AppService;
use AppModelUser;
use HyperfDbConnectionDb;

// ✅ 正确（修复后）
namespace App\Service;
use App\Model\User;
use Hyperf\DbConnection\Db;
```

Composer 自动加载器（`composer.json`：`"App\" => "src/"`）把 `App\Service` 和损坏的 `AppService` 同时映射到同名类 → **`Cannot declare class` 致命错误** → 业务 worker 一接请求就崩溃重启（日志里 `worker abnormal exit` 刷屏）→ 接口 500。

**损坏范围**（本次实测）：仅 3 个 Service 文件的头部 + 2 个测试文件，**方法体与其余 84 个 PHP 文件全部完好**——损伤高度集中在「某一次 AI/工具写文件」的那一批。

| 文件 | 坏在哪 | 修复 commit |
|---|---|---|
| `src/Service/AuthService.php` | `namespace` + 2 个 `use`（另补 `use Throwable;`） | `667c4bd` |
| `src/Service/MistakeAccountService.php` | `namespace` + 4 个 `use` | `667c4bd` |
| `src/Service/MistakeProfileService.php` | `namespace` + 3 个 `use` | `667c4bd` |
| `tests/AIAnalysisTest.php` / `tests/AccountIdTest.php` | `namespace`/`use` | `667c4bd` |

> ⚠️ **关键教训**：`git log --oneline` 显示这个错误从文件**首次写入**起就存在，每个历史 commit 都是坏的——**回滚 git 无法修复**，因为没有「干净的旧版本」。只能手工把反斜杠补回去。

### 排查方法（如何快速锁定）

```bash
# 1. 先看 api 日志有没有 PHP 致命错误（这一步直接定性）
docker logs guanlan-api-1 --tail 50 | grep -iE 'fatal|Cannot declare'

# 2. 看到 Cannot declare class XXX → 去查那个类的源码头部反斜杠
docker exec guanlan-api-1 head -12 /opt/guanlan/src/Service/AuthService.php

# 3. 全库扫一遍还有没有同类损坏（缺反斜杠的伪命名空间）
#    特征：namespace/use 后 App、Hyperf 等根命名空间直接连大写字母（本该有 \）
grep -rnE '^(namespace|use)\s+(App|Hyperf|GuzzleHttp|Psr|Symfony)[A-Z]' apps/api/src
```

### 为什么会发生 & 如何预防

- **成因**：通过某些 shell 管道 / AI 编辑工具写入 PHP 文件时，反斜杠 `\` 被当作转义符吞掉（`\S`→`S`、`\M`→`M`、`\D`→`D`），而 PHP 命名空间恰恰**全靠反斜杠分隔**，一吞就废。
- **预防**：
  1. 任何**自动生成/批量写入 PHP 源码**的操作后，先 `php -l`（语法检查）或 `grep` 扫一遍反斜杠，再 commit。
  2. 部署后**别只看容器 `healthy`**，务必 curl 一个真实业务接口（如登录）确认返回业务响应而非 500。
  3. api 的 healthcheck 可加一个真实 HTTP 探针（如 `/api/v1/health`），而不只是 `fsockopen` 探端口，才能暴露这类「端口活着但业务挂了」的问题。

---

## 九、经验教训

1. **先分清 4xx 与 5xx**：404 多半指向前端路由，502/503/504 指向**网关到后端**这一段，500 指向**应用本身**（PHP Fatal、未捕获异常）。三者排障方向完全不同，别混为一谈。
2. **怀疑路由前，先在容器内自证**：`docker exec web wget .../路由`。容器内能渲染，问题就在网关，不在 Nuxt。
3. **nginx 会缓存 upstream DNS**：upstream 换成 IP 字面量的容器部署里，后端一换 IP，必须重启/重建 nginx。
4. **生产只用一套 compose**，并用别名固化命令，从制度上杜绝混用。
5. **容器 `healthy` ≠ 业务正常**：healthcheck 探端口活着不代表业务没挂。接口 500 时**第一动作是看应用日志**（`docker logs <api>`），PHP 的 `Fatal error` 一眼定性。
6. **警惕「写入即损坏」**：AI/脚本批量写 PHP 文件可能吞掉反斜杠，把命名空间写废。写后先 `php -l` / `grep` 扫一遍再提交。这类错误 git 历史里全是坏的，**回滚救不了**，只能手工修。
7. **接口 500 别只重启**：重启治不了代码级 Fatal。先日志定位是「声明冲突 / 类找不到 / 语法错误」哪一类，再对症。

---

**文档版本**: v1.1
**创建时间**: 2026-09-28
**更新记录**: v1.1 新增「案例二：登录接口 500（PHP 反斜杠被剥离）」（commit `667c4bd`），并扩充经验教训 §5-§7
**关联文档**: `DEPLOYMENT-CHECKLIST.md`、`504-TROUBLESHOOTING.md`、`AI-ANALYSIS-DEPLOYMENT-REPORT.md`
