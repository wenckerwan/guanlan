# DEV30 unified search implementation

## Runtime source and route contract

- `GET /api/v1/search?q=&type=&limit=` retains existing endpoint and authentication middleware. Adds `concept` and `history` filters.
- Mayuan reads **current `published` subtree** of `content` in `mayuan_records`, on the existing MySQL instance/account. Schema is `MAYUAN_SEARCH_DATABASE` (default `mayuan`), restricted to ASCII letters, digits and underscore. SELECT only; no schema creation, draft/version access, seed fallback or progress access.
- Concept URL: `/mayuan/?concept=<stable node id>`.
- History uses `HistoryService::latestPublished()`, then independently filters event publication to original/verified and skips grouped archive rows. URL: `/history/?event=<stable event id>`.
- Existing seven source types continue using their current models. Prediction now applies the same hidden status exclusion as ArticleService.
- Every hit carries type/title/snippet/url/meta plus subject/matchLocation. Exact title ranks first, title/curated alias second, summary/body third; ties sort deterministically by title and URL. Single auditable alias maps `实践是检验认识真理性的唯一标准` to `实践检验标准`, quoted from that node's current summary.

## Access and count behavior

- History and Mayuan are loaded only for authenticated users, matching their content endpoints. Per-source state explicitly reports available/unpublished/unavailable/login_required; errors never silently substitute local seeds.
- Guests cannot search question contents. Article guests search public list title/summary only, so locked html cannot be inferred from a hit/count. Authenticated users can search html. Public paper/mock list metadata remains searchable.
- Existing MistakeAccess SQL visibility remains, with an additional exact `canViewCode` check before projection/counts. Hidden article records are excluded before searching.
- No shared search cache; controller emits Cache-Control: no-store.
- All matching existing SQL rows and published concept/event records are ranked before limiting. `total` counts all matching visible results of the selected type; `groups` counts visible matches across types; `returned` and `truncated` describe the displayed subset. No old 12-per-type sampling. UI explicitly says first N of total and asks to refine when truncated.
- Reactive computed request URL updates for keyword and filter. Router history restores both fields. Pending, failure/retry and confirmed empty results render separate states.

## Verification and RED/GREEN evidence

1. KnowledgeSearchTest initially failed loading the absent projection class. After implementation passed. Adding grouped archive fixture failed the published-record count before group exclusion; then passed.
2. New frontend tests initially failed reactive URL and pending/error assertions (6 pass/2 fail); implementation gave 8/8. Added live script behavior harness using actual Vue reactivity and esbuild TypeScript transform: submit/filter/back/forward now 9/9. This is a component-script harness, not a browser or real Nuxt fetch integration test.
3. SearchServiceTest failed `guest cannot infer locked article body through hits/counts`; corrected guest SQL body clause; now passes. Also covers hidden predictions, guest protected-source non-access, pending history, authenticated body metadata, missing DB source degradation, private mistake exclusion.
4. MayuanSearchSourceTest passes: current published rather than draft, absent record no fallback, unsafe schema identifier rejected.
5. Entire frontend suite passed 97/97 before the final behavioral test was added; final focused suite 9/9. Root runs final broader gates/build.
6. PHP tests run with `C:/Users/Administrator/AppData/Local/Temp/guanlan-php-1022/php.exe -d extension_dir=C:/Users/Administrator/AppData/Local/Temp/guanlan-php-1022/ext -d extension=mbstring`; all three new tests pass and are registered in `tools/test_stability.py`.
7. Release fixture projects 204 records (101 concepts + 103 independent published history). First-ranked target URLs verified: 矛盾→contradiction; 实践→practice; 遵义会议→p4-r055-e01; 虎门销烟→p1-r001-e01; 五四运动→p2-r038-e01. Projection plus six pure searches took **5.06 ms** locally. This excludes MySQL/network/HTTP and is not a live production latency claim. Fixture JSON is test input only.

## Limitations and deployment assumptions

- Accurate counts currently materialize matching SQL rows and scan the small current published knowledge datasets. No persistent index or search engine. Very broad queries can cost more memory/latency as datasets grow; a production DB benchmark remains necessary before claiming capacity. This is deliberate to avoid misleading sampled counts/ranking.
- Runtime Mayuan schema/account follows existing compose same-instance credentials. Deployments using a different schema must set MAYUAN_SEARCH_DATABASE; permission/table absence yields an explicit unavailable source.
- Live DB, browser and deployment verification were not performed by this subtask. No external writes/deployments.

## Owned changed files

- apps/api/src/Controller/SearchController.php
- apps/api/src/Service/SearchService.php
- apps/api/src/Service/MayuanSearchSource.php
- apps/api/src/Support/KnowledgeSearch.php
- apps/api/tests/KnowledgeSearchTest.php
- apps/api/tests/SearchServiceTest.php
- apps/api/tests/MayuanSearchSourceTest.php
- apps/web/pages/search.vue
- apps/web/types/api.ts
- apps/web/utils/search.mjs
- apps/web/tests/search.test.mjs
- tools/test_stability.py
- docs/dev30/search-report.md
