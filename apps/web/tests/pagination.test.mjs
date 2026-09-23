import test from 'node:test'
import assert from 'node:assert/strict'
import { normalizePage, pageCount, pageWindow, paginationState } from '../utils/pagination.mjs'

test('normalizes invalid and out-of-range page values', () => {
  assert.equal(normalizePage(undefined, 100, 20), 1)
  assert.equal(normalizePage(-4, 100, 20), 1)
  assert.equal(normalizePage('3', 100, 20), 3)
  assert.equal(normalizePage(99, 41, 20), 3)
  assert.equal(normalizePage(5, 0, 20), 1)
})

test('calculates page counts for empty, partial and exact result sets', () => {
  assert.equal(pageCount(0, 20), 1)
  assert.equal(pageCount(21, 20), 2)
  assert.equal(pageCount(40, 20), 2)
})

test('builds a compact page window at the start, middle and end', () => {
  assert.deepEqual(pageWindow(1, 200, 20), [1, 2, 3, 4, 5])
  assert.deepEqual(pageWindow(5, 200, 20), [3, 4, 5, 6, 7])
  assert.deepEqual(pageWindow(10, 200, 20), [6, 7, 8, 9, 10])
})

test('reports navigation and visible item bounds', () => {
  assert.deepEqual(paginationState(2, 45, 20), {
    page: 2,
    pages: 3,
    hasPrevious: true,
    hasNext: true,
    start: 21,
    end: 40,
  })
  assert.deepEqual(paginationState(1, 0, 20), {
    page: 1,
    pages: 1,
    hasPrevious: false,
    hasNext: false,
    start: 0,
    end: 0,
  })
})
