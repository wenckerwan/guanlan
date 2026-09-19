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
  level: string
  title: string
  summary: string
  type: string
  updatedAt: string
  tag: string
  subjectSlug: string
  chapterId: string
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

export type HomePayload = {
  hotspots: Hotspot[]
  documents: DocumentCard[]
  subjects: SubjectSummary[]
}

export type ApiEnvelope<T> = {
  data: T
}
