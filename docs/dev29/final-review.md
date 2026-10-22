# dev.29 independent final review

## 提交前处理记录（主代理，2026-10-22）

以下原始审查意见保留供追溯。后续处理：史纲报告已更新为持久化失败只读及 17 项定向测试；发布报告已明确快照仅覆盖已跟踪和未忽略文件。发布指纹现包含 storage/dataset、dataset-manifest.json、import-manifest.json、storage/history，并通过激活前拒绝漂移的回归测试。原始意见 1–3 已处理或按其要求明确边界。

2026-10-22. Reviewed the uncommitted scoped source, tests, plan, and dev.29 reports. No code was changed and no tests were rerun by this reviewer.

## Remaining findings

1. **P2 — The history verification report describes the old behavior and test counts.** `docs/dev29/history-stability-report.md:8-20` says recovery failure always permits editing and says the targeted history suite has 13 tests. `apps/web/components/history/ui/HistoryExplorer.vue:85-95,171-190` now intentionally holds a draft read-only after failed local persistence and retries that draft before loading; the expanded suite has additional race and storage-failure cases. Update the behavior and reported validation after the final run so reviewers can tell an offline API failure from an IndexedDB write failure. The release report's `9/9` count at `docs/dev29/release-stability-report.md:13-15` also predates the added dataset-gate regression.

2. **P2 — The workspace guard excludes ignored files from its read-only claim.** `tools/test_stability.py:44-49` now hashes tracked and nonignored untracked files, which closes the earlier same-status-content gap. It does not observe ignored locations such as `.env.production`, `storage/raw/`, `storage/previews/`, or deployment backups. No current test is known to touch these, but `docs/dev29/release-stability-report.md:7` should describe the actual guarded surface instead of saying any workspace rewrite is detected. If those paths must be covered, fingerprint the specific sensitive locations without hashing generated dependencies.

3. **P2 — Mounted dataset drift is detected only after the image switch.** `tools/deploy/release.py:16,117-126,171-184` fingerprints Compose/nginx/env and the SQL backup, but not the host `storage/dataset*` and `storage/import-manifest.json` mounted into the API by `compose.production.yml`. If one of those files changes after `prepare`, `activate` can enter `switch` and the new API's dataset verifier can fail. Automatic rollback then starts the old API against the same invalid mount and can also fail. Fingerprint the verifier inputs at `prepare` and reject their drift before switching; the synthetic Docker rehearsal cannot reveal this host-file scenario.

## Blocking findings resolved during review

- `HistoryExplorer.vue:85-95,181-190` keeps a failed reconnect write read-only, retains the in-memory draft, and flushes it before a later restore. `apps/web/tests/history-reconnect.test.mjs` covers the failure path.
- `guanlan-study.ts:244-265,337-364` now writes a per-save pending revision and conditionally clears it in one IndexedDB transaction. `save()` reconciles against the current record in its write transaction and unions local-only attempts by ID. The targeted test covers a newer tab's pending note and a stale tab's attempt preservation.
- `tools/deploy/release.py:128-133` now runs the read-only dataset integrity check before the API starts, while still bypassing the Dockerfile's migration loop. The unit contract and synthetic Docker rehearsal fixture were updated for this command.

## Verdict and evidence boundary

**No remaining blocking implementation finding in the reviewed scope; documentation corrections requested.** The SSE reader requires a nonempty `done` event before saving and limits compatibility fallback to 404/405/501. History remains partitioned by verified account, preserves offline/401 drafts, and now handles the reviewed same-account tab races. Release uses prebuilt image IDs, retains SQL/config backups, and rolls back images without database restoration. The scoped changes preserve the approved theme, navigation, and routes.

The current CI workflow is configured to run Node suites from lockfiles, PHP fixture tests, a Nuxt build, an isolated browser fixture including an A-to-B cookie switch, and a synthetic-data Docker rehearsal. The browser and Docker checks do not call real models, send mail, mutate production users, or prove production deployment. The reports' local results should not be presented as a completed CI run or production rehearsal.
