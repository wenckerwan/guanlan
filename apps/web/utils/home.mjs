const LEVEL_WEIGHT = { S: 3, A: 2, B: 1, C: 0 }

export function buildHomeSummary({ subjects }) {
  return {
    subjects: subjects.slice(0, 6),
  }
}
