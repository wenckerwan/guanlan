import test from 'node:test'
import assert from 'node:assert/strict'
import { displayAnswer, gradePaper, isCorrect, normalizeAnswer, progressPercent, scoreOf, sectionOf, toLetters } from '../utils/quiz.mjs'

test('normalizes answers regardless of order, case and separators', () => {
  assert.equal(normalizeAnswer('a,b,d'), 'ABD')
  assert.equal(normalizeAnswer('D A B'), 'ABD')
  assert.equal(normalizeAnswer(undefined), '')
  assert.deepEqual(toLetters('b,a'), ['A', 'B'])
  assert.equal(displayAnswer('c, a'), 'AC')
})

test('judges single and multiple choice by exact letter set', () => {
  assert.equal(isCorrect('B', 'B'), true)
  assert.equal(isCorrect('b', 'B'), true)
  assert.equal(isCorrect('ABD', 'A,B,D'), true)
  assert.equal(isCorrect('ABC', 'ABD'), false)
  assert.equal(isCorrect('', 'A'), false)
  assert.equal(isCorrect('A', ''), false)
})

test('grades only objective questions and counts blanks', () => {
  const questions = [
    { no: 1, type: 'single', score: 1, answer: 'A', objective: true },
    { no: 2, type: 'single', score: 1, answer: 'B', objective: true },
    { no: 3, type: 'multi', score: 2, answer: 'ACD', objective: true },
    { no: 34, type: 'analyse', score: 10, answer: '', objective: false },
  ]
  const result = gradePaper(questions, { 1: 'A', 2: 'C', 3: 'ACD' })
  assert.equal(result.objective, 3)
  assert.equal(result.right, 2)
  assert.equal(result.wrong, 1)
  assert.equal(result.blank, 0)
  assert.equal(result.score, 3)
  assert.equal(result.accuracy, 67)
})

test('counts unanswered questions as blank, not wrong', () => {
  const questions = [
    { no: 1, type: 'single', score: 1, answer: 'A', objective: true },
    { no: 2, type: 'single', score: 1, answer: 'B', objective: true },
  ]
  const result = gradePaper(questions, { 1: 'A' })
  assert.equal(result.right, 1)
  assert.equal(result.wrong, 0)
  assert.equal(result.blank, 1)
  assert.equal(result.accuracy, 100)
})

test('handles an empty paper without dividing by zero', () => {
  const result = gradePaper([], {})
  assert.equal(result.score, 0)
  assert.equal(result.accuracy, 0)
  assert.equal(result.objective, 0)
})

test('resolves mock paper sections and scores by question number', () => {
  assert.deepEqual(sectionOf(1), { key: 'single', label: '单项选择题' })
  assert.equal(sectionOf(16).key, 'single')
  assert.equal(sectionOf(17).key, 'multi')
  assert.equal(sectionOf(33).key, 'multi')
  assert.equal(sectionOf(34).key, 'analyse')
  assert.equal(scoreOf(16), 1)
  assert.equal(scoreOf(17), 2)
  assert.equal(scoreOf(38), 10)
})

test('progressPercent clamps to the 0—100 range', () => {
  assert.equal(progressPercent(0, 10), 0)
  assert.equal(progressPercent(5, 10), 50)
  assert.equal(progressPercent(20, 10), 100)
  assert.equal(progressPercent(3, 0), 0)
})