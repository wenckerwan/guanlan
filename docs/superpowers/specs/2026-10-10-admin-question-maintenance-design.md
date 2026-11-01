# 题库维护设计

用户要求继续已确认的后台开发顺序，本批基于未发布的错题/评论分支5f06dbc继续题库维护；保持现有导航、玻璃UI和routes。此前生产仍为51436bb，本批不部署。

## 题目维护

- 新增AdminQuestionService和专用AdminQuestionController；POST /admin/papers/{pid}/questions创建（201），PATCH /admin/questions/{id}更新，GET /admin/questions/{id}管理员详情。
- 创建字段no/type/typeCn/stem/material/options/answer/answerText/analysis/module/moduleName/kaodian/score/sortOrder；year/label从存在的试卷继承。允许type为single/multi/analyse/discern/essay/material/simple；客观题至少2项A-H字符串选项，单选1个正确字母，多选至少2个且均在选项中，键不被array_values丢弃；主观题无选项，至少有答案要点或解析。
- 编辑身份pid/no/id不可变，明确拒绝改变以保护旧引用；PATCH仅更新显式字段，未知字段422。保留其余来源字段。新增或实际修改type/options/answer/answerText/analysis时最终题型/选项/答案必须一致，非法请求不写入。
- 资料数据集中已有49道客观题缺答案、62道主观题缺要点/解析；只读、排序或一般元数据修改可保留这些旧内容，不自动补答案。已有表单提交变更字段与expectedRevision，未变的原始字段不重新写入；答案相关修改需补齐一致状态。同值字段不算语义变化。
- 分值为有限数字0..9999.9，最多1位小数；题号1..65535整数；排序为32位有符号整数；typeCn<=32/module<=16/moduleName<=64/kaodian<=191/answer<=16，stem/material/answerText不超过TEXT字节容量，analysis<=1MiB。题干非空，空选项/重复字母/未知格式不静默忽略。
- 新迁移questions增加sort_order与revision，已有sort_order按no回填，revision默认1；不重建/清空题库。QuestionResource给出sortOrder/revision，QuestionService.paperQuestions和管理员题列表以sort_order/no/id稳定排序；题号与ID不因排序改变。
- PATCH必须expectedRevision>=1，版本过期409保留草稿。创建题号重复409，不存在试卷/题目404，失效管理员403。
- 新建/更新在同一事务内按papers→questions维护锁顺序保护试卷删除/导入，锁定父试卷与题目，创建后重计question_count；审计与保存原子提交，失败回滚。旧AdminService.updateQuestion也修复键丢失或委托新服务，不能保留可绕过的破坏选项写路径。
- 试卷pid一旦存在不可改变；PaperResource暴露现有sortOrder，UI编辑加载原值。本批不重写已有试卷增删流程。

## 管理页面

papers保留列表与题分页；新增题/完整编辑器、排序字段、版本冲突反馈，身份字段只读。保存期间阻止重复提交、试卷/题页切换、删除；脏草稿取消/离开/切换需确认；迟到请求不能覆盖当前试卷或新表单。成功后重读题目页和试卷计数。保留已有试卷创建、编辑、删除确认，加入保存锁与正确排序载入。

## 模拟卷与预测

- GET /admin/mocks/{slug}?page=1&perPage=20：新AdminLibraryService核对新鲜管理员，404不存在，返回{mock:MockResource.make模型+sourceFile,questions:{items:MockResource.questions,total,page,perPage}}，题目按no/id稳定，分页归一。无写入。
- mocks页面只读详情：原始题干、带字母选项、答案与解析按文字呈现（不v-html未处理正文），独立加载/失败/重试、迟到响应隔离，手机局部滚动；URL mock选卷，mockPage题页。更新源数据必须先对账且保留导入保护。
- predictions旧分页API归一有效页，管理员DTO与PATCH返回sortOrder；现有sortOrder写API校验32位整数、新鲜权限并保留当前发布/评论状态。UI增加明确排序保存、URL页码、错误/空/加载/重试、操作锁，保留原发布/评论设置。

## 验证

真实MySQL先失败后实现：迁移不丢数据/回填、A-H键、全部校验、重复/缺失、ID与已有引用不变、创建计数、并发创建/保存冲突、审计失败回滚、导入/删除互斥；模拟卷超过100题的完整分页，预测排序不改变状态。更新旧最小fixture以真实反映新增schema。

模拟API浏览器：新增/编辑/排序/保存/重读、409草稿、错误重试、连续切换和晚到响应、脏表单/保存锁、选项错误不提交、模拟卷分页与预测保存、390px与键盘。全分支静态审查、既有117项数据库/20项HTTP、55项前端、PHP语法/构建/文档。生产功能与本批开发证据分别记录。
