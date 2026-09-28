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

export type Hotspot = {
  slug: string
  level: string
  priority: string
  title: string
  summary: string
  type: string
  period: string
  updatedAt: string
  tag: string
  subjectSlug: string
  chapterId: string
  url: string
}

export type DocumentCard = {
  title: string
  meta: string
  progress: number
  cover: string
  tone: string
}

export type SubjectSummary = {
  slug: string
  name: string
  short: string
  tone: string
  detail: string
  count: number
}

export type HomeStats = {
  questions: number
  papers: number
  hotspots: number
  predictions: number
  analysis: number
  mistakes: number
}

export type HomePayload = {
  hotspots: Hotspot[]
  documents: DocumentCard[]
  subjects: SubjectSummary[]
  stats?: HomeStats
}

export type ApiEnvelope<T> = {
  data: T
}

/* ---------- 真题回顾 ---------- */

export type PaperSection = {
  key: string
  per: number | null
  total: number | null
  raw: string
}

export type Paper = {
  pid: string
  year: number
  label: string
  kind: string
  questionCount: number
  totalScore: number
  answeredCount: number
  sections: PaperSection[]
}

export type QuestionOption = Record<string, string>

export type Question = {
  id: number
  pid: string
  year: number
  label: string
  no: number
  type: string
  typeCn: string
  score: number
  module: string
  moduleName: string
  kaodian: string
  stem: string
  material: string
  options: QuestionOption
  answer: string | null
  analysis: string | null
  answerText: string | null
  accuracy: string
  objective: boolean
}

export type PaperDetail = {
  paper: Paper
  questions: Question[]
}

export type QuestionPage = {
  items: Question[]
  total: number
  page: number
  perPage: number
}

export type ModuleSummary = {
  module: string
  name: string
  count: number
}

/* ---------- 文章类 ---------- */

export type OutlineItem = {
  level: number
  title: string
}

export type ArticleSummary = {
  slug: string
  title: string
  summary: string
  priority: string
  outline: OutlineItem[]
  category?: string
  sourceFile?: string
  wordCount?: number
  release?: boolean
  period?: string
  level?: string
  type?: string
  tag?: string
  layer?: string
  locked?: boolean
}

export type ArticleDetail = ArticleSummary & {
  html: string
}

/* ---------- 错题分析 ---------- */

export type MistakeOption = {
  label: string
  text: string
  mark: string
}

export type MistakeItem = {
  id: number
  module: string
  chapter: string
  sourceNo: string
  kaodian: string
  stem: string
  options: MistakeOption[]
  myAnswer: string
  correctAnswer: string
  qType: string
  errorType: string
  action: string
}

export type MistakeStudent = {
  code: string
  name: string
  relation: string
  itemCount: number
  moduleCounts: Record<string, number>
  errorTypes: Record<string, number>
}

export type MistakeItemsPayload = {
  student: MistakeStudent
  items: MistakeItem[]
  total: number
  page: number
  perPage: number
}

export type HandbookSection = {
  title: string
  html: string
}

export type Handbook = {
  id: number
  studentCode: string
  module: string
  title: string
  sectionCount: number
  sections: HandbookSection[]
  html?: string
}

/* ---------- 模拟押题 ---------- */

export type Mock = {
  slug: string
  title: string
  summary: string
  totalScore: number
  durationMinutes: number
  questionCount: number
  answeredCount: number
}

export type MockQuestion = {
  no: number
  type: string
  typeCn: string
  score: number
  module: string
  moduleName: string
  kaodian: string
  stem: string
  options: QuestionOption
  answer: string | null
  analysis: string | null
}

export type MockDetail = {
  mock: Mock
  questions: MockQuestion[]
}

/* ---------- 用户与后台 ---------- */

export type User = {
  id: number
  email: string
  displayName: string
  role: string
  status: string
  mistakeCode?: string
  createdAt: string
}

export type AuthPayload = {
  token: string
  user: User
}

export type Favorite = {
  id: number
  targetType: string
  targetId: string
  title: string
  url: string
  createdAt: string
}

export type Note = {
  id: number
  targetType: string
  targetId: string
  title: string
  content: string
  createdAt: string
}

export type StudyStats = {
  total: number
  right: number
  wrong: number
  accuracy: number
  byModule: { module: string; total: number; right: number; accuracy: number }[]
  favorites: number
  notes: number
}

export type ProgressRecord = {
  id: number
  scope: string
  ref: string
  label: string
  status: string
  progress: number
  correctCount: number
  wrongCount: number
  lastSeenAt: string
}

export type AdminOverview = {
  counts: Record<string, number>
  recentUsers: { id: number; email: string; displayName: string; role: string; createdAt: string }[]
  todayUsers: number
}

export type AdminAttempt = {
  id: number
  userId: number
  source: string
  sourceRef: string
  questionRef: string
  module: string
  chosen: string
  correct: string
  isRight: boolean
  createdAt: string
}

export type SearchHitType = 'question' | 'paper' | 'analysis' | 'hotspot' | 'prediction' | 'mock' | 'mistake'

export type SearchHit = {
  type: SearchHitType
  title: string
  snippet: string
  url: string
  meta: string
}

export type SearchResult = {
  items: SearchHit[]
  total: number
  groups: Record<string, number>
}
