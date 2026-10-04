# 马原知识宇宙 V2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development to implement and review tasks. Track execution below.

**Goal:** Deliver a running independent learning platform with persistent per-user storage and a dormant, honest Guanlan integration.

**Architecture:** Nuxt/Vue front end, framework-independent PHP domain API served locally by PHP, with Hyperf production adapter and Docker configuration. SQLite is the runnable development persistence; PDO MySQL is production persistence. Guanlan identity and synchronization live behind a server-side adapter.

**Tech Stack:** Node 22, Nuxt 4/Vue 3/TypeScript, PHP 8.3, PDO SQLite/MySQL, Hyperf 3.1 Docker target.

## Global constraints

- Preserve V1 unchanged; all development is under `v2/`.
- Independent warm-white/teal design; no stage map, forced unlocks, or rankings.
- Guanlan source is read-only; real integration is not claimed until actual interfaces are available and tested.
- Nuxt 4 is chosen because official Nuxt 3 documentation marks v3 end-of-life; Vue and API compatibility, not host styling, is shared.
- Development accounts must be visibly labeled and disabled outside explicit development mode.
- Backend decides authenticated user and exercise correctness; database data survives restart.
- Reuse 101 concepts,126 relations,19 comparisons,30 original exercises; do not claim full examination coverage.
- Server keeps self mastery, visits, practice and recall distinct; imported V1 answers remain labeled historical/unverified.

## Shared API contract (freeze before implementing)

All success responses `{data: ...}`, errors `{error:{code,message}}`; `/api/v2` paths. Browser uses same-origin proxy to PHP and HttpOnly session cookie. JSON requests required for mutations.

- `GET /session`: `{mode:'development'|'guanlan',user:null|{id,name,role},integration:{configured:boolean,status:string}}`.
- `POST /dev-login` body `{account:'learner'|'second'|'admin'}`; development-only accounts. `POST /logout`.
- `GET /content`: published content `{contentVersion,modules,nodes,relations,comparisons,exercises,sources}`; exercises exclude answers/explanations before submission.
- `GET /state`: `{revision,nodes:{[id]:{visited,mastery,masteryUpdatedAt,updatedAt}},attempts:[],recalls:[],favorites:string[],notes:[{id,nodeId,content,updatedAt}],resume:{view,nodeId},summary:{visited,mastered,attempts,correct,due},sync:{status,pending}}`.
- `POST /events`: `{id,type,payload,baseRevision?}`; types visit `{nodeId}`, mastery `{nodeId,mastery}`, favorite `{nodeId,favorited}`, note `{id,nodeId,content}` (empty deletes), recall `{nodeId,rating:'again'|'hard'|'good',mode}`, resume `{view,nodeId}`. Returns full state. Identical event replay is idempotent; different body with reused id rejected. Revision conflict reports409. Do not blindly replay a self-assessment conflict.
- `POST /attempts`: `{eventId,exerciseId,chosen:number,reason:string}`; returns `{correct,answer,explanation,state}`; backend-authoritative answer and idempotent record.
- `GET /export`: V2 backup; `POST /import`: `{progress:object,confirm:true}`; validate and merge V1 or V2 after preview/confirmation.
- `GET /admin/content`: `{draft,published,revision,revisions}`; `PUT /admin/content`: `{content,baseRevision}` saves validated draft; `POST /admin/publish`: `{revision}`; `POST /admin/rollback`: `{version}`. Only role admin. Saved drafts and publication revisions persistent; malformed references rejected.
- Integration server config, auth/me validation, one-time code protocol interface, outbox summary revision/retry behind adapter; no fake SSO implementation. Current Guanlan snapshot lacks code-exchange/summary endpoints. Document explicit enablement and contract gap.

## Task 1 — Backend and persistence

Files `apps/api/**`, `tests/api/**`; owner backend agent.
- [x] Write and run failing security, grading, user isolation and idempotency tests.
- [x] Implement shared API, storage, validation, admin and import.
- [x] Add PDO MySQL compatibility, Hyperf adapter, production config and Guanlan adapter/outbox.
- [x] Run tests and self-review; report tested vs deployment-only targets.

## Task 2 — Learning application

Files `apps/web/**`; owner frontend agent.
- [x] Create Nuxt 4 project with TypeScript, independently styled responsive shell.
- [x] Implement chapter/global/focus map and stable star view, search and relation explanations.
- [x] Implement comparison judgments,3 labs,3 recall tasks and case prompts, quiz and per-user notebook.
- [x] Implement session, explicit dev login, state/event calls, conflict handling and offline queue with user isolation.
- [x] Implement preview-confirm import, export, content admin draft/publish/rollback UI.
- [x] Build and fix compilation; self-review states, keyboard and phone usability.

## Task 3 — Runtime and delivery

Files root package/scripts/docs; owner main agent.
- [x] Download official portable PHP locally and enable PDO drivers; install front-end dependencies.
- [x] Provide one-command start/build/test and Docker/MySQL target.
- [x] Test real HTTP API and browser core flows, persistence, mobile and account switches.
- [x] Independent final review; fix important findings.
- [x] Write honest run/deployment/integration/verification documentation and save screenshot.

