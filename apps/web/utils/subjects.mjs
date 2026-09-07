export function getSubjectBySlug(subjects, slug) {
  return subjects.find((subject) => subject.slug === slug)
}

export function validateSubjects(subjects) {
  const errors = []
  const seen = new Set()

  for (const subject of subjects) {
    if (seen.has(subject.slug)) errors.push(`重复的学科 slug：${subject.slug}`)
    seen.add(subject.slug)
    for (const hotspot of subject.hotspots ?? []) {
      if (!hotspot) errors.push(`学科${subject.name.slice(0, 1)}${subject.name.length > 1 ? subject.name.slice(-1) : ''}存在空的热点映射`)
    }
  }

  return { valid: errors.length === 0, errors }
}
