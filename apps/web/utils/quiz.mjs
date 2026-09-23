/**
 * 判分与作答工具：与页面解耦，便于 node --test 直接覆盖。
 */

export function normalizeAnswer(value) {
  return String(value ?? '').replace(/[\s,，、]/g, '').toUpperCase().split('').sort().join('')
}

/** 单选/多选判分：字母集合完全一致才算对。 */
export function isCorrect(chosen, correct) {
  const a = normalizeAnswer(chosen)
  const b = normalizeAnswer(correct)
  return a !== '' && a === b
}

/** 把 `A,B,C` 归一化为 `ABC`（展示用）。 */
export function displayAnswer(value) {
  return normalizeAnswer(value)
}

export function toLetters(value) {
  return normalizeAnswer(value).split('')
}

/** 模拟卷按题号分段：1—16 单选、17—33 多选、34—38 分析题。 */
export function sectionOf(no) {
  if (no <= 16) return { key: 'single', label: '单项选择题' }
  if (no <= 33) return { key: 'multi', label: '多项选择题' }
  return { key: 'analyse', label: '材料分析题' }
}

export function scoreOf(no) {
  if (no <= 16) return 1
  if (no <= 33) return 2
  return 10
}

/**
 * 统计一份作答：只对客观题计分。
 * answers: { [no]: 'A' | 'ABD' }
 */
export function gradePaper(questions, answers) {
  let score = 0
  let objective = 0
  let right = 0
  let wrong = 0
  let blank = 0

  for (const question of questions) {
    if (!question.objective && question.type !== 'single' && question.type !== 'multi') continue
    objective += 1
    const chosen = answers[question.no] ?? answers[question.id] ?? ''
    if (!chosen) {
      blank += 1
      continue
    }
    if (isCorrect(chosen, question.answer)) {
      right += 1
      score += Number(question.score) || 0
    } else {
      wrong += 1
    }
  }

  return {
    score,
    objective,
    right,
    wrong,
    blank,
    accuracy: objective - blank > 0 ? Math.round((right / (objective - blank)) * 100) : 0,
  }
}

export function progressPercent(done, total) {
  if (!total) return 0
  return Math.max(0, Math.min(100, Math.round((done / total) * 100)))
}
