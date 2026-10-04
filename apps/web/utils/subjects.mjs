export function getSubjectBySlug(subjects, slug) {
  return subjects.find((subject) => subject.slug === slug)
}

export function validateSubjects(subjects) {
  const errors = []
  const seen = new Set()

  for (const subject of subjects) {
    if (seen.has(subject.slug)) errors.push(`重复的学科 slug：${subject.slug}`)
    seen.add(subject.slug)
  }

  return { valid: errors.length === 0, errors }
}
