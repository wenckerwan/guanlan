# 正文编辑 3A 验证

新增管理员详情、预览与保存接口，共用 CommonMark 2.10.3 与 HTML 白名单渲染；支持安全 GFM 表格/列表，生成 toc-* 目录与去空白字数。原HTML元数据更新保持原字节；明确正文编辑时重新安全渲染。原HTML与Markdown不自动互相转换。

两类页面共用编辑器，保留独立格式草稿、保存反馈、409草稿保护与最新版本确认、未保存离开确认、只采用最新详情/预览请求；预览使用再次净化的sandbox iframe。

MySQL 77项通过（既有57+正文20），覆盖权限、预览/保存一致、安全HTML/URL、UTF-8与大小、旧文保留、版本冲突、维护事务回滚及列表版本字段。浏览器4组通过，无pageerror；浏览器使用模拟API。Composer审计无advisories，新增5包且保留既有锁定版本；可复验镜像 guanlan-admin-articles-test。

本批新增迁移 2026_10_10_000001_add_article_editor_metadata.php。release Dockerfile 安装新锁依赖，继承的生产API已检查DOM与mbstring能力。后续3B补不可变历史与只读源数据对账；本批不解除任何导入保护。

最终复核发现的元数据保存重复提交正文问题已补失败用例并修复：既有正文与格式均未改时省略format/body，保持原HTML；正文保存后采用服务端规范化结果为基线。完整浏览器脚本复验通过。
