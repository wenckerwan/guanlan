# Task 4 Report

## Status

Implemented Task 4 only.

- `DatasetReader::list()` and `DatasetReader::missing()` verify the dataset before reading or checking dataset files.
- `ArticleSeeder`, `MistakeSeeder`, and `PaperQuestionSeeder` verify the dataset at the first line of `run()`.
- API Docker startup now runs `php bin/verify-dataset.php` before the migration retry loop.
- `tools/lint.sh` mounts the repository tools directory and runs the executable verifier contract test alongside PHP linting.
- `DatasetManifestVerifierTest.php` now checks Seeder source order and Docker startup order.
- Task 5 was not implemented.

## Commit

Implementation commit: recorded after commit creation.

## Commands and outputs

- `git diff --check`: passed; Git emitted only expected LF-to-CRLF conversion warnings.
- `python -m unittest tools.ingest.test_dataset_manifest -v`: `Ran 10 tests ... OK`.
- Static source-order/Docker/lint contract check: `Task 4 static contract check: PASS`.
- PHP executable test and PHP syntax lint: skipped because PHP is unavailable on the host.
- Docker verification: skipped and Docker was not started, per instruction.

## Concerns

- Runtime PHP execution and the container lint command remain unverified until a PHP/Docker runtime is available.
- The lint script still uses its existing `~/guanlan` working-directory assumption; only the required test mount and invocation were added.
