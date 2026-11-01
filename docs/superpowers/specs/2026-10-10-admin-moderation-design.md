# 错题与评论管理设计

用户已确认发布后的下一批规划。先准备生产真实流程验收（用户亲自管理员登录），同时完成本批开发；认证信息不读取、不输出。

## 范围与接口

- GET /admin/mistakes/students：q搜索编号/姓名/绑定邮箱；page/perPage，返回items,total,page,perPage，items保留原字段。稳定sort_order/id，页码回归有效页，空结果page1。
- GET /admin/mistakes/students/{code}/items：module、errorType精确组合筛选，返回原code/name与分页字段，新增filters.modules/errorTypes为该考生全部错题的去重值。稳定sort_order/id，页码归一。缺失考生404，普通用户403。前台个人错题接口不改变。
- GET /admin/comments：兼容既有status/articleType/page/perPage，新增q正文关键词、articleSlug精确定位、userId正整数、userQ姓名/邮箱搜索。status仅空/pending/approved，articleType仅空/analysis/hotspot/prediction；非法参数422。关键词的%、_、=按字面检索。分页返回items,total,page,perPage。
- 新读服务核对最新有效管理员，不凭缓存角色；列出失效/已删除用户的既有评论时保留原列表行为。

## 页面与状态

评论独立筛选字段q/status/articleType/articleSlug/userId/userQ/page/perPage，URL恢复，提交筛选回第1页，最新请求才能渲染。文章标识链接和同文章/同用户定位；单行保存期间防重复，删除确认含回复影响，操作后重读当前筛选并回归有效页。错误、空、加载分别显示，手机表格横滚位于容器内。

错题URL使用studentQ/studentPage/studentPerPage，code选择考生，module/errorType/page/perPage筛选条目。支持不在当前考生页的code详情读取；切换考生/路由时各读取隔离。统计/学生/条目/画像各有loading/error/retry，不把失败显示成空或默认画像。画像与条目编辑未保存时离开/切换确认，保存时禁用切换；保存结果只影响原考生，条目保存后刷新筛选和元信息，避免错题留在不匹配筛选中。

## 实现选择与验证

使用现有Hyperf/Nuxt与必要专用读服务；不重写后台框架、不新增索引迁移，先验证现有数据规模。比仅客户端筛选更能覆盖全部记录；相比统一改造全部页面，本批边界易于回归。

真实MySQL验证大数据分页、组合筛选、特殊字符、稳定排序、越界、缺失考生、最新角色权限；模拟API浏览器验证URL恢复、晚到响应、失败重试、画像/条目切换和操作后刷新。生产真实验收依赖用户完成浏览器登录，不能以模拟API测试标成完成。
