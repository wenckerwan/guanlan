export type KnowledgePoint = {
  title: string
  summary: string
}

export type SubjectChapter = {
  id: string
  title: string
  summary: string
  points: KnowledgePoint[]
}

export type Subject = {
  slug: string
  name: string
  short: string
  tone: string
  detail: string
  intro: string
  hotspots: string[]
  chapters: SubjectChapter[]
}

export const subjects: Subject[] = [
  {
    slug: 'marxism',
    name: '马克思主义基本原理',
    short: '马原',
    tone: 'jade',
    detail: '教材、720题解析与原理专题',
    intro: '围绕唯物论、辩证法、认识论和历史唯物主义，建立从概念到真题迁移的原理框架。',
    hotspots: ['人工智能与科技自立自强'],
    chapters: [
      { id: 'materialism', title: '世界的物质性及发展规律', summary: '从物质、意识和联系发展入手，掌握分析题的基本方法。', points: [{ title: '物质与意识的辩证关系', summary: '理解意识的能动作用及其发挥条件。' }, { title: '联系和发展的基本环节', summary: '用系统观念分析矛盾、联系和发展。' }, { title: '对立统一规律', summary: '把握矛盾同一性与斗争性的辩证关系。' }] },
      { id: 'epistemology', title: '认识世界和改造世界', summary: '以实践为基础，梳理认识运动和真理发展的逻辑。', points: [{ title: '实践是认识的基础', summary: '掌握实践的观点在材料分析中的落点。' }, { title: '真理与价值', summary: '区分真理尺度与价值尺度并说明统一条件。' }, { title: '认识运动的基本规律', summary: '从感性认识到理性认识再回到实践。' }] },
    ],
  },
  {
    slug: 'maoism',
    name: '毛泽东思想和中国特色社会主义理论体系概论',
    short: '毛中特',
    tone: 'red',
    detail: '教材与真题规律',
    intro: '以马克思主义中国化时代化为主线，串联新民主主义革命、社会主义建设和改革开放理论成果。',
    hotspots: ['乡村全面振兴与共同富裕'],
    chapters: [
      { id: 'new-democracy', title: '新民主主义革命理论', summary: '掌握革命道路、基本经验和统一战线等高频考点。', points: [{ title: '新民主主义革命道路', summary: '理解农村包围城市、武装夺取政权的历史逻辑。' }, { title: '统一战线', summary: '梳理不同历史时期统一战线的任务和原则。' }, { title: '党的建设伟大工程', summary: '把握思想建党、政治建军和群众路线。' }] },
      { id: 'reform-opening', title: '改革开放和社会主义现代化建设新时期', summary: '围绕改革开放历史进程，掌握理论创新与实践成就。', points: [{ title: '社会主义初级阶段基本路线', summary: '理解一个中心、两个基本点的内在联系。' }, { title: '改革开放的意义', summary: '从制度、实践和人民生活说明改革开放成就。' }, { title: '中国特色社会主义道路', summary: '把握道路、理论、制度、文化的统一。' }] },
    ],
  },
  {
    slug: 'xi-jinping-thought',
    name: '习近平新时代中国特色社会主义思想概论',
    short: '习思想',
    tone: 'gold',
    detail: '教材、时政和命题包',
    intro: '聚焦新时代坚持和发展中国特色社会主义的总任务、总体布局和战略布局，连接时政热点与理论表达。',
    hotspots: ['十五五规划与开局之年', '党的二十届五中全会'],
    chapters: [
      { id: 'new-era', title: '新时代坚持和发展中国特色社会主义', summary: '理解新时代的历史方位、主要矛盾和战略安排。', points: [{ title: '新时代的历史方位', summary: '从党和国家事业发展全局定位新时代。' }, { title: '中国式现代化', summary: '梳理中国式现代化的中国特色和本质要求。' }, { title: '“两个结合”', summary: '理解马克思主义基本原理同中国具体实际、中华优秀传统文化相结合。' }] },
      { id: 'high-quality-development', title: '以中国式现代化全面推进强国建设', summary: '围绕高质量发展、新质生产力和共同富裕整理命题线索。', points: [{ title: '高质量发展', summary: '掌握新时代发展的首要任务和内在要求。' }, { title: '新质生产力', summary: '理解科技创新、产业升级与生产力发展的关系。' }, { title: '共同富裕', summary: '从发展为了人民、依靠人民、成果由人民共享作答。' }] },
    ],
  },
  {
    slug: 'modern-china-history',
    name: '中国近现代史纲要',
    short: '史纲',
    tone: 'blue',
    detail: '教材、历年真题与周年专题',
    intro: '按照历史进程梳理近代以来中国人民争取民族独立、人民解放和国家富强、人民幸福的实践。',
    hotspots: ['长征胜利 90 周年'],
    chapters: [
      { id: 'revolution', title: '新民主主义革命时期', summary: '从旧民主主义革命转向新民主主义革命，掌握重要会议和历史经验。', points: [{ title: '五四运动与马克思主义传播', summary: '理解新民主主义革命的开端及思想基础。' }, { title: '遵义会议', summary: '掌握独立自主解决中国革命实际问题的历史意义。' }, { title: '抗日战争的中流砥柱', summary: '从民族大义、战略持久和群众动员分析中国共产党的作用。' }] },
      { id: 'socialist-construction', title: '社会主义建设和改革开放', summary: '理解社会主义制度建立、探索和改革开放的历史转折。', points: [{ title: '社会主义基本制度的确立', summary: '梳理过渡时期总路线和社会主义改造。' }, { title: '真理标准问题讨论', summary: '把握改革开放新时期思想解放的历史作用。' }, { title: '中国特色社会主义进入新时代', summary: '联系党的十八大以来历史性成就和变革。' }] },
    ],
  },
  {
    slug: 'ideology-law',
    name: '思想道德与法治',
    short: '思法',
    tone: 'violet',
    detail: '教材与真题索引',
    intro: '从人生理想、爱国主义、道德建设和法治实践出发，形成可用于选择题和分析题的价值判断框架。',
    hotspots: [],
    chapters: [
      { id: 'ideals-beliefs', title: '领悟人生真谛 把握人生方向', summary: '理解人生观、价值观和理想信念的基本内容。', points: [{ title: '人生观的主要内容', summary: '掌握人生目的、人生态度和人生价值的关系。' }, { title: '理想信念', summary: '理解理想信念对成长成才和事业发展的作用。' }, { title: '个人理想与社会理想', summary: '把握个人选择与国家民族需要的统一。' }] },
      { id: 'morality-law', title: '践行社会主义核心价值观', summary: '结合道德建设和法治实践，训练规范表达。', points: [{ title: '社会主义核心价值观', summary: '区分国家、社会、个人三个层面的价值要求。' }, { title: '社会主义道德建设', summary: '理解为人民服务和集体主义的道德要求。' }, { title: '全面依法治国', summary: '掌握法治国家、法治政府、法治社会一体建设。' }] },
    ],
  },
  {
    slug: 'world-politics',
    name: '当代世界经济与政治',
    short: '时政',
    tone: 'orange',
    detail: '热点预测与国际专题',
    intro: '追踪国际格局、全球治理和中国外交，把当年时政热点转化为可复习的专题线索。',
    hotspots: ['2026 APEC 中国主场'],
    chapters: [
      { id: 'international-pattern', title: '当代世界政治格局与发展趋势', summary: '从和平与发展时代主题出发，分析国际力量对比和全球治理。', points: [{ title: '和平与发展仍是时代主题', summary: '理解世界多极化和经济全球化的曲折发展。' }, { title: '国际力量对比', summary: '从综合国力、国家利益和战略互动分析国际关系。' }, { title: '全球治理体系改革', summary: '掌握构建人类命运共同体的世界意义。' }] },
      { id: 'china-diplomacy', title: '中国的和平发展道路', summary: '围绕独立自主和平外交政策和全球伙伴关系整理答题素材。', points: [{ title: '独立自主的和平外交政策', summary: '理解中国外交政策宗旨、基本原则和立场。' }, { title: '构建人类命运共同体', summary: '从价值理念、实践平台和全球意义展开作答。' }, { title: '共建“一带一路”', summary: '联系开放合作、互利共赢和高质量发展。' }] },
    ],
  },
]
