# Browser Visit Statistics Implementation Plan

**Goal:** Display total and today's visits on the homepage, counting each browser at most once per rolling 3600 seconds.

**Architecture:** A shared client tracker obtains a server-generated HttpOnly cookie before posting a visit. MySQL transactions serialize increments and store permanent totals. Read and write endpoints are network-only and independent of cached homepage content.

**Tech Stack:** Nuxt 3, Hyperf 3.1, MySQL, Node built-in tests.

## Constraints

- Use Asia/Shanghai for daily buckets; crossing midnight does not reset the rolling window.
- No IP addresses, account IDs, fingerprinting, fabricated history, automatic dwell-time counts, or offline replay.
- Exclude admin, offline, hidden pages, and localhost development. Auxiliary apps on the same origin share the cookie.
- Use existing repository patterns; keep existing untracked files intact.

## Tasks

- [x] Add failing client tests for bootstrap-before-write, deduplication requests, failures, navigation, exclusion, and cache policy. Add standalone PHP policy tests for exact boundary and midnight.
- [x] Add VisitWindow, visit tables, VisitService, and VisitController. Lock the singleton totals row before changing a browser row and daily counters; use a transaction retry for deadlocks. GET issues a cookie but never increments; POST requires the cookie. Every response uses Cache-Control: no-store.
- [x] Add shared visit-tracker.js, Nuxt navigation hook, compact homepage statistics component, and script inclusion in the meetings and mayuan entries.
- [x] Run Node tests, production builds, PHP syntax/structural checks and available policy tests. Check homepage desktop/mobile rendering and errors using a local preview and mock API; document that mocks do not validate MySQL.
- [x] Run the real HTTP/MySQL integration test and restart-persistence check on a disposable Hyperf/MySQL stack.

## Deployment Verification

API startup already runs migrations. On a disposable MySQL database, run the integration script with real Hyperf vendor dependencies. Verify first visit, duplicates, 3600-second boundary, midnight, parallel POSTs, cookie flags, restart persistence and no-store responses. No production deployment is authorized by this plan.
