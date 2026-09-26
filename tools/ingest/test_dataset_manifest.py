import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from tools.ingest import build_all, dataset_manifest


class DatasetManifestTest(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        root = Path(self.temp_dir.name)
        self.dataset_dir = root / "dataset"
        self.dataset_dir.mkdir()
        self.source_path = root / "source-manifest.json"
        self.output = root / "manifest.json"

        datasets = {
            "analysis_articles.json": [{"id": 1}],
            "hotspots.json": [{"id": 1}],
            "mistakes.json": {"items": [{"id": 1}], "students": []},
            "mocks.json": [{"id": 1}],
            "papers.json": [{"id": 1}],
            "predictions.json": [{"id": 1}],
            "questions.json": [{"id": 1}],
            "stats.json": {"items": [{"id": 1}], "students": []},
        }
        for name, payload in datasets.items():
            (self.dataset_dir / name).write_text(
                json.dumps(payload, ensure_ascii=False), encoding="utf-8"
            )
        self.source_path.write_text(
            json.dumps(
                {"asset_count": 2, "logical_document_count": 2},
                ensure_ascii=False,
            ),
            encoding="utf-8",
        )

    def tearDown(self):
        self.temp_dir.cleanup()

    def test_describes_lists_and_grouped_maps(self):
        self.assertEqual(dataset_manifest.describe_payload([1, 2]), (2, {}))
        self.assertEqual(
            dataset_manifest.describe_payload(
                {"items": [1, 2], "students": [1], "meta": "x"}
            ),
            (3, {"items": 2, "students": 1}),
        )

    def test_builds_sorted_manifest_linked_to_source(self):
        manifest = dataset_manifest.build_manifest(self.dataset_dir, self.source_path)
        self.assertEqual(manifest["schema_version"], 1)
        self.assertEqual(
            list(manifest["datasets"]), sorted(dataset_manifest.REQUIRED_DATASETS)
        )
        self.assertEqual(manifest["source_manifest"]["asset_count"], 2)
        self.assertEqual(
            manifest["source_manifest"]["path"], "storage/import-manifest.json"
        )
        self.assertEqual(
            manifest["source_manifest"]["sha256"],
            dataset_manifest.digest(self.source_path),
        )
        self.assertEqual(manifest["totals"]["files"], 8)

    def test_hash_changes_when_dataset_changes(self):
        before = dataset_manifest.build_manifest(self.dataset_dir, self.source_path)
        (self.dataset_dir / "questions.json").write_text(
            '[{"id":2}]', encoding="utf-8"
        )
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

    def test_publish_manifest_uses_dataset_and_source_paths(self):
        result = build_all.publish_manifest(
            self.dataset_dir, self.source_path, self.output
        )
        self.assertEqual(result["totals"]["files"], 8)
        self.assertTrue(self.output.is_file())

    def test_main_runs_builders_in_declared_order(self):
        calls = []

        def run(command, cwd):
            calls.append(Path(command[1]).name)
            return mock.Mock(returncode=0)

        with (
            mock.patch.object(
                build_all,
                "STEPS",
                [("first.py", "first.json"), ("second.py", "second.json")],
            ),
            mock.patch.object(build_all.subprocess, "run", side_effect=run),
            mock.patch.object(
                build_all,
                "publish_manifest",
                return_value={
                    "datasets": {},
                    "totals": {"bytes": 0, "files": 8, "items": 0},
                },
            ),
        ):
            result = build_all.main()

        self.assertEqual(result, 0)
        self.assertEqual(calls[:2], ["first.py", "second.py"])

    def test_main_refreshes_source_manifest_before_publish(self):
        events = []

        def run(command, cwd):
            events.append(Path(command[1]).name)
            return mock.Mock(returncode=0)

        def publish(dataset_dir, source_manifest, output):
            events.append("publish")
            return {
                "datasets": {},
                "totals": {"bytes": 0, "files": 8, "items": 0},
            }

        with (
            mock.patch.object(build_all, "STEPS", [("builder.py", "data.json")]),
            mock.patch.object(build_all.subprocess, "run", side_effect=run),
            mock.patch.object(build_all, "publish_manifest", side_effect=publish),
        ):
            result = build_all.main()

        self.assertEqual(result, 0)
        self.assertEqual(events, ["builder.py", "manifest.py", "publish"])

    def test_manifest_failure_preserves_previous_official_manifest(self):
        self.output.write_text("previous-manifest\n", encoding="utf-8")
        calls = []

        def run(command, cwd):
            calls.append(Path(command[1]).name)
            return mock.Mock(returncode=0 if calls[-1] == "builder.py" else 1)

        with (
            mock.patch.object(build_all, "STEPS", [("builder.py", "data.json")]),
            mock.patch.object(build_all, "DATASET_MANIFEST", self.output),
            mock.patch.object(build_all.subprocess, "run", side_effect=run),
            mock.patch.object(build_all, "publish_manifest") as publish,
        ):
            result = build_all.main()

        self.assertEqual(result, 1)
        self.assertEqual(calls, ["builder.py", "manifest.py"])
        self.assertEqual(self.output.read_text(encoding="utf-8"), "previous-manifest\n")
        publish.assert_not_called()

    def test_builder_failure_preserves_previous_official_manifest(self):
        self.output.write_text("previous-manifest\n", encoding="utf-8")
        calls = []

        def run(command, cwd):
            calls.append(Path(command[1]).name)
            return mock.Mock(returncode=1)

        with (
            mock.patch.object(
                build_all,
                "STEPS",
                [("fails.py", "failed.json"), ("must-not-run.py", "other.json")],
            ),
            mock.patch.object(build_all, "DATASET_MANIFEST", self.output),
            mock.patch.object(build_all.subprocess, "run", side_effect=run),
            mock.patch.object(build_all, "publish_manifest") as publish,
        ):
            result = build_all.main()

        self.assertEqual(result, 1)
        self.assertEqual(calls, ["fails.py"])
        self.assertEqual(self.output.read_text(encoding="utf-8"), "previous-manifest\n")
        publish.assert_not_called()


if __name__ == "__main__":
    unittest.main()
