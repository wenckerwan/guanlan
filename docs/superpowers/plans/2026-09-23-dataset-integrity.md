# Dataset Integrity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a deterministic dataset manifest and prevent migrations or Seeders from running against missing or modified generated data.

**Architecture:** Python owns manifest generation because it already owns dataset construction. A dependency-free PHP verifier recomputes the same metadata before database work and is also called by each dataset-backed Seeder. The source manifest is linked by hash, while production only consumes committed generated artifacts.

**Tech Stack:** Python 3.11+ standard library, PHP 8.3, Hyperf 3.1, Docker Compose, PowerShell/Bash verification scripts.

## Global Constraints

- Source materials under `F:\2027考研资料\考研政治` are read-only and must never be modified, renamed, or deleted.
- Build only from the copied materials already inside the development workspace.
- Manifest output must be deterministic: no timestamps, machine paths, or unstable key ordering.
- Dataset validation must finish before migrations, table deletion, or Seeder writes.
- Required datasets are exactly: `analysis_articles.json`, `hotspots.json`, `mistakes.json`, `mocks.json`, `papers.json`, `predictions.json`, `questions.json`, `stats.json`.
- This plan executes before `2026-09-23-production-deployment.md`.

---

## File Structure

- Create `tools/ingest/dataset_manifest.py`: pure manifest construction, validation metadata, and atomic writer.
- Create `tools/ingest/test_dataset_manifest.py`: standard-library unit tests using temporary fixtures.
- Modify `tools/ingest/build_all.py`: run source manifest refresh and write the dataset manifest only after all builders succeed.
- Create `apps/api/src/Seeder/DatasetManifestVerifier.php`: dependency-free runtime verifier.
- Create `apps/api/tests/DatasetManifestVerifierTest.php`: executable PHP test without PHPUnit.
- Create `apps/api/bin/verify-dataset.php`: container entry point for integrity checks.
- Modify `apps/api/src/Seeder/DatasetReader.php`: verify once before reading generated data.
- Modify `apps/api/seeders/ArticleSeeder.php`, `MistakeSeeder.php`, `PaperQuestionSeeder.php`: verify before any database access or writes.
- Modify `apps/api/Dockerfile`: verify before migration and seeding.
- Modify `tools/verify.ps1`, `tools/verify.sh`, `tools/lint.sh`: include the new tests.
- Create `storage/dataset-manifest.json`: committed current manifest.
- Modify `README.md`, `docs/development-plan.md`, `CHANGELOG.md`, `VERSION`: release documentation.

---

### Task 1: Deterministic Python Manifest Builder

**Files:**
- Create: `tools/ingest/test_dataset_manifest.py`
- Create: `tools/ingest/dataset_manifest.py`

**Interfaces:**
- Produces: `REQUIRED_DATASETS: tuple[str, ...]`
- Produces: `digest(path: Path) -> str`
- Produces: `describe_payload(payload: object) -> tuple[int, dict[str, int]]`
- Produces: `build_manifest(dataset_dir: Path, source_manifest_path: Path) -> dict`
- Produces: `write_manifest(output: Path, manifest: dict) -> None`

- [ ] **Step 1: Write failing unit tests**

Create tests that build temporary source and dataset files and assert:

```python
class DatasetManifestTest(unittest.TestCase):
    def test_describes_lists_and_grouped_maps(self):
        self.assertEqual(dataset_manifest.describe_payload([1, 2]), (2, {}))
        self.assertEqual(
            dataset_manifest.describe_payload({"items": [1, 2], "students": [1], "meta": "x"}),
            (3, {"items": 2, "students": 1}),
        )

    def test_builds_sorted_manifest_linked_to_source(self):
        manifest = dataset_manifest.build_manifest(self.dataset_dir, self.source_path)
        self.assertEqual(manifest["schema_version"], 1)
        self.assertEqual(list(manifest["datasets"]), sorted(dataset_manifest.REQUIRED_DATASETS))
        self.assertEqual(manifest["source_manifest"]["asset_count"], 2)
        self.assertEqual(manifest["totals"]["files"], 8)

    def test_hash_changes_when_dataset_changes(self):
        before = dataset_manifest.build_manifest(self.dataset_dir, self.source_path)
        (self.dataset_dir / "questions.json").write_text('[{"id":2}]', encoding="utf-8")
        after = dataset_manifest.build_manifest(self.dataset_dir, self.source_path)
        self.assertNotEqual(
            before["datasets"]["questions.json"]["sha256"],
            after["datasets"]["questions.json"]["sha256"],
        )

    def test_rejects_missing_dataset(self):
        (self.dataset_dir / "stats.json").unlink()
        with self.assertRaisesRegex(FileNotFoundError, "stats.json"):
            dataset_manifest.build_manifest(self.dataset_dir, self.source_path)

    def test_atomic_writer_is_byte_stable(self):
        manifest = dataset_manifest.build_manifest(self.dataset_dir, self.source_path)
        dataset_manifest.write_manifest(self.output, manifest)
        first = self.output.read_bytes()
        dataset_manifest.write_manifest(self.output, manifest)
        self.assertEqual(first, self.output.read_bytes())
```

- [ ] **Step 2: Run the tests and verify RED**

Run: `python -m unittest tools.ingest.test_dataset_manifest -v`

Expected: FAIL because `tools.ingest.dataset_manifest` does not exist.

- [ ] **Step 3: Implement the minimal builder**

Implement the constants and functions with these rules:

```python
REQUIRED_DATASETS = (
    "analysis_articles.json",
    "hotspots.json",
    "mistakes.json",
    "mocks.json",
    "papers.json",
    "predictions.json",
    "questions.json",
    "stats.json",
)

def describe_payload(payload: object) -> tuple[int, dict[str, int]]:
    if isinstance(payload, list):
        return len(payload), {}
    if isinstance(payload, dict):
        groups = {key: len(value) for key, value in sorted(payload.items()) if isinstance(value, list)}
        return sum(groups.values()), groups
    raise ValueError("dataset root must be a list or object")
```

Use 1 MiB streaming SHA-256 reads. Parse the source manifest for `asset_count` and `logical_document_count`. Write JSON with `ensure_ascii=False`, `indent=2`, `sort_keys=True`, a final newline, and `Path.replace()` from a sibling temporary file.

- [ ] **Step 4: Run the tests and verify GREEN**

Run: `python -m unittest tools.ingest.test_dataset_manifest -v`

Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add tools/ingest/dataset_manifest.py tools/ingest/test_dataset_manifest.py
git commit -m "feat: add deterministic dataset manifest builder"
```

---

### Task 2: Integrate Manifest Generation with the Ingest Pipeline

**Files:**
- Modify: `tools/ingest/build_all.py`
- Modify: `tools/ingest/test_dataset_manifest.py`
- Modify: `tools/verify.ps1`
- Modify: `tools/verify.sh`

**Interfaces:**
- Consumes: `dataset_manifest.build_manifest()` and `dataset_manifest.write_manifest()` from Task 1.
- Produces: `storage/dataset-manifest.json` only after all builders and `manifest.py` succeed.

- [ ] **Step 1: Add a failing integration test**

Add a test around a new helper:

```python
def test_publish_manifest_uses_dataset_and_source_paths(self):
    result = build_all.publish_manifest(self.dataset_dir, self.source_path, self.output)
    self.assertEqual(result["totals"]["files"], 8)
    self.assertTrue(self.output.is_file())
```

- [ ] **Step 2: Run the focused test and verify RED**

Run: `python -m unittest tools.ingest.test_dataset_manifest.DatasetManifestTest.test_publish_manifest_uses_dataset_and_source_paths -v`

Expected: FAIL because `publish_manifest` does not exist.

- [ ] **Step 3: Implement pipeline integration**

Add:

```python
SOURCE_MANIFEST = HERE.parents[1] / "storage" / "import-manifest.json"
DATASET_MANIFEST = HERE.parents[1] / "storage" / "dataset-manifest.json"

def publish_manifest(dataset_dir: Path, source_manifest: Path, output: Path) -> dict:
    manifest = build_manifest(dataset_dir, source_manifest)
    write_manifest(output, manifest)
    return manifest
```

Change `main()` so a failed builder returns nonzero without touching the current manifest. After all builders pass, run `manifest.py`; only then call `publish_manifest()`. Print one line per file and one totals line from the returned structure.

Add `python -m unittest discover -s tools/ingest -p "test_*.py" -v` to both verification scripts.

- [ ] **Step 4: Verify tests and deterministic output**

Run:

```powershell
python -m unittest discover -s tools/ingest -p "test_*.py" -v
python tools/ingest/build_all.py
$first = (Get-FileHash storage/dataset-manifest.json -Algorithm SHA256).Hash
python tools/ingest/build_all.py
$second = (Get-FileHash storage/dataset-manifest.json -Algorithm SHA256).Hash
if ($first -ne $second) { throw "dataset manifest is not deterministic" }
```

Expected: all tests pass, both hashes match, manifest reports 8 files.

- [ ] **Step 5: Commit**

```bash
git add tools/ingest/build_all.py tools/ingest/test_dataset_manifest.py tools/verify.ps1 tools/verify.sh storage/import-manifest.json storage/dataset-manifest.json storage/dataset/*.json
git commit -m "feat: publish auditable dataset manifest"
```

---

### Task 3: PHP Runtime Verifier

**Files:**
- Create: `apps/api/tests/DatasetManifestVerifierTest.php`
- Create: `apps/api/src/Seeder/DatasetManifestVerifier.php`
- Create: `apps/api/bin/verify-dataset.php`

**Interfaces:**
- Produces: `DatasetManifestVerifier::verify(string $basePath = BASE_PATH): void`
- Produces: CLI `php bin/verify-dataset.php [base-path]`, exit 0 on success and 1 on mismatch.

- [ ] **Step 1: Write an executable failing PHP test**

The test creates a temporary `storage/dataset` tree, invokes the Python builder once for fixture metadata, then asserts:

```php
$verifier = new \App\Seeder\DatasetManifestVerifier();
$verifier->verify($fixtureRoot);

file_put_contents($fixtureRoot . '/storage/dataset/questions.json', '[{"id":99}]');
try {
    $verifier->verify($fixtureRoot);
    throw new RuntimeException('expected integrity failure');
} catch (RuntimeException $exception) {
    assert(str_contains($exception->getMessage(), 'questions.json'));
    assert(str_contains($exception->getMessage(), 'sha256'));
}
```

Use a fresh verifier instance for the negative assertion so successful verification caching cannot hide the mutation. Remove the temporary directory in `finally`.

- [ ] **Step 2: Run in the API image and verify RED**

Run: `docker compose run --rm api php -d zend.assertions=1 -d assert.exception=1 tests/DatasetManifestVerifierTest.php`

Expected: FAIL with class `DatasetManifestVerifier` not found.

- [ ] **Step 3: Implement the verifier and CLI**

The verifier must:

- parse JSON with `JSON_THROW_ON_ERROR`;
- compare all required files, bytes, SHA-256, records and groups;
- compare source manifest SHA-256, `asset_count`, and `logical_document_count`;
- collect all mismatches into `Dataset integrity check failed:\n- ...`;
- cache only successful verification per absolute base path.

The CLI catches `Throwable`, writes the message to STDERR, and exits 1. A successful run prints `Dataset integrity OK: 8 files`.

- [ ] **Step 4: Run the executable test and CLI**

Run:

```bash
docker compose run --rm api php -d zend.assertions=1 -d assert.exception=1 tests/DatasetManifestVerifierTest.php
docker compose run --rm api php bin/verify-dataset.php
```

Expected: test exits 0 and CLI prints `Dataset integrity OK: 8 files`.

- [ ] **Step 5: Commit**

```bash
git add apps/api/tests/DatasetManifestVerifierTest.php apps/api/src/Seeder/DatasetManifestVerifier.php apps/api/bin/verify-dataset.php
git commit -m "feat: verify dataset integrity before import"
```

---

### Task 4: Enforce Verification Before Database Writes

**Files:**
- Modify: `apps/api/src/Seeder/DatasetReader.php`
- Modify: `apps/api/seeders/ArticleSeeder.php`
- Modify: `apps/api/seeders/MistakeSeeder.php`
- Modify: `apps/api/seeders/PaperQuestionSeeder.php`
- Modify: `apps/api/Dockerfile`
- Modify: `tools/lint.sh`

**Interfaces:**
- Consumes: `DatasetManifestVerifier::verify()` from Task 3.
- Produces: fail-fast startup order `verify -> migrate -> seed -> start`.

- [ ] **Step 1: Add a failing contract assertion**

Extend `apps/api/tests/DatasetManifestVerifierTest.php` to inspect the three Seeder source files and assert each calls `DatasetManifestVerifier::verify()` before its first database operation. Inspect `Dockerfile` and assert `verify-dataset.php` occurs before `migrate --force`.

- [ ] **Step 2: Run and verify RED**

Run: `docker compose run --rm api php -d zend.assertions=1 -d assert.exception=1 tests/DatasetManifestVerifierTest.php`

Expected: FAIL because Seeders and Docker startup do not call the verifier.

- [ ] **Step 3: Add fail-fast calls**

At the first line of each data-backed Seeder `run()` method, call:

```php
(new DatasetManifestVerifier())->verify();
```

Call the verifier from `DatasetReader::list()` and `DatasetReader::missing()` as defense in depth. Update Docker `CMD` to:

```dockerfile
CMD ["sh", "-c", "php bin/verify-dataset.php && until php bin/hyperf.php migrate --force; do echo 'waiting for mysql...'; sleep 3; done && php bin/hyperf.php db:seed --force && php bin/hyperf.php start"]
```

Add the executable PHP test to `tools/lint.sh`.

- [ ] **Step 4: Verify normal and negative paths**

In the WSL native verification copy:

```bash
docker compose build api
docker compose run --rm api php -d zend.assertions=1 -d assert.exception=1 tests/DatasetManifestVerifierTest.php
cp storage/dataset/questions.json /tmp/questions.json.clean
printf '\n' >> storage/dataset/questions.json
! docker compose run --rm api php bin/verify-dataset.php
mv /tmp/questions.json.clean storage/dataset/questions.json
docker compose run --rm api php bin/verify-dataset.php
```

Expected: the modified file fails with a `questions.json sha256` mismatch; restored data passes.

- [ ] **Step 5: Commit**

```bash
git add apps/api/src/Seeder/DatasetReader.php apps/api/seeders/ArticleSeeder.php apps/api/seeders/MistakeSeeder.php apps/api/seeders/PaperQuestionSeeder.php apps/api/Dockerfile tools/lint.sh
git commit -m "fix: block database startup on dataset drift"
```

---

### Task 5: Dataset Integrity Documentation and Release State

**Files:**
- Modify: `README.md`
- Modify: `docs/development-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces: user-facing build, verification, and troubleshooting commands.
- Leaves version bump to the final production deployment plan so V0.1-dev.5 is released once.

- [ ] **Step 1: Update documentation**

Document:

- `python tools/ingest/build_all.py` generates both datasets and `storage/dataset-manifest.json`;
- how to interpret missing file, hash mismatch, and source manifest mismatch errors;
- F-drive materials remain read-only and are never mounted into Docker;
- development-plan item 1.4 implementation artifacts and verification commands.

- [ ] **Step 2: Run documentation and integrity checks**

Run:

```powershell
python tools/doclink.py
python tools/phpcheck.py
python tools/phpcheck_selftest.py
python -m unittest discover -s tools/ingest -p "test_*.py" -v
git diff --check
```

Expected: all commands exit 0.

- [ ] **Step 3: Record only actual results**

Add command counts and Docker negative/positive results to the unreleased `V0.1-dev.5` CHANGELOG section. Keep development-plan item 1.4 as `进行中` until the production plan's final integrated verification passes.

- [ ] **Step 4: Commit**

```bash
git add README.md docs/development-plan.md CHANGELOG.md
git commit -m "docs: document dataset integrity workflow"
```

