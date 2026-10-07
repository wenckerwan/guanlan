# Browser visit statistics

The homepage displays cumulative visits and today's visits. A browser is counted at most once per rolling 3600 seconds from its last successful count. Refreshes and navigation register activity; staying on a page does not. Daily buckets use Asia/Shanghai; midnight does not reset the cooldown.

## Identity and persistence

- `GET /api/v1/stats/visits` reads totals and initializes `guanlan_browser` if absent. It never counts a visit.
- `POST /api/v1/stats/visit` requires that cookie and returns `{data: {total, today, date, startedAt, counted}}`.
- The one-year cookie is HttpOnly, SameSite=Lax, Path=/ and Secure in production. Cookies disabled means no counting. Login/logout does not change identity.
- MySQL stores only a SHA-256 hash of a random 256-bit identifier and its last count timestamp, plus daily and cumulative counters. No IP, account data or fingerprint is collected.
- Writers lock the singleton total row inside one transaction before browser eligibility checks and counter changes. Database restarts retain data; standard MySQL backups include all three tables.
- All responses use no-store. PWA stats endpoints use the network. Offline activity is not replayed, and failures show `--`.
- Nuxt main routes (including history), the meetings page and the hosted mayuan app share this tracker. Admin, offline, localhost, and invisible pages do not register. Separate-origin standalone apps are not included.
- Web Locks serializes cookie bootstrap across tabs where supported. On browsers without Web Locks, simultaneous first-ever tabs may establish distinct cookies before sharing a final cookie. Subsequent requests with the same cookie remain transactionally deduplicated.
- Clearing cookies, using another browser profile, or opening a new private session establishes a new browser. This is approximate browser traffic, not actual people or an anti-fraud metric.

## Deployment

API startup runs the existing migrations, including `2026_10_07_000001_create_site_visits_tables.php`. Deploy the API, main web and mayuan web together. No Redis dependency was added. The first accepted visit establishes startedAt; historical counts are not fabricated.

On an isolated test stack connected to a database whose name ends in `_visit_test`, set VISIT_TEST_BASE_URL to the API's `/api/v1` base and run:

```sh
php tests/VisitWindowTest.php
php tests/VisitHttpIntegrationTest.php
```

The HTTP test modifies last_counted_at and leaves test counts behind, so it explicitly rejects databases without that suffix. It checks cookies, no-store, required cookie, refresh deduplication, expired windows, parallel POSTs and daily/total consistency. Check Secure on the HTTPS production configuration, restart persistence and failure responses separately before deployment.

## Verification on 2026-10-07

- Main web: 55 Node tests passed on the production baseline; production build passed. PWA remains on the separate development branch.
- Mayuan: production build passed (existing chunk-size and dependency deprecation warnings).
- PHP 8.3.35: visit-window test and new PHP source lint passed.
- Offline structural checker and its self-test passed.
- Playwright: homepage renders counts using mock responses at 1440x900 and 390x844; mobile has no horizontal overflow. Tooltip is keyboard-accessible and a mocked 503 clears counts to `--`. Mock tests do not validate PHP HTTP behavior or MySQL locks.
- Real Hyperf/MySQL HTTP integration and restart persistence remain unverified because this workstation lacks vendor dependencies, MySQL and Docker. No production deployment performed.
