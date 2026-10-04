const LEVEL_WEIGHT = { S: 3, A: 2, B: 1, C: 0 }

export function sortHotspots(items) {
  return [...items].sort((left, right) => {
    const levelDelta = (LEVEL_WEIGHT[right.level] ?? 0) - (LEVEL_WEIGHT[left.level] ?? 0)
    if (levelDelta !== 0) return levelDelta
    return String(right.updatedAt).localeCompare(String(left.updatedAt))
  })
}

export function buildHomeSummary({ hotspots, subjects }) {
  return {
    hotspots: sortHotspots(hotspots).slice(0, 4),
    subjects: subjects.slice(0, 6),
  }
}
