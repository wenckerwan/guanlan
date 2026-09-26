import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from tools.ingest import copy_raw


class CopyRawTest(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.root = Path(self.temp_dir.name)
        self.source = self.root / "source"
        self.destination = self.root / "raw"
        self.manifest = self.root / "import-manifest.json"
        self.source.mkdir()

    def tearDown(self):
        self.temp_dir.cleanup()

    def write_source(self, relative: str, content: bytes) -> dict:
        path = self.source / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)
        return {
            "bytes": len(content),
            "paths": [relative],
            "sha256": hashlib.sha256(content).hexdigest(),
        }

    def write_manifest(self, assets: list[dict]) -> None:
        self.manifest.write_text(
            json.dumps({"assets": assets}, ensure_ascii=False), encoding="utf-8"
        )

    def test_copies_manifest_assets_preserving_paths_and_skips_matches(self):
        asset = self.write_source("nested/资料.md", b"study-data")
        self.write_manifest([asset])
        source_bytes = (self.source / "nested/资料.md").read_bytes()

        first = copy_raw.copy_assets(self.source, self.destination, self.manifest)
        second = copy_raw.copy_assets(self.source, self.destination, self.manifest)

        self.assertEqual(first, {"bytes": 10, "copied": 1, "files": 1, "skipped": 0})
        self.assertEqual(second, {"bytes": 10, "copied": 0, "files": 1, "skipped": 1})
        self.assertEqual(
            (self.destination / "nested/资料.md").read_bytes(), source_bytes
        )
        self.assertEqual((self.source / "nested/资料.md").read_bytes(), source_bytes)

    def test_rejects_source_content_that_does_not_match_manifest(self):
        asset = self.write_source("资料.md", b"original")
        asset["sha256"] = "0" * 64
        self.write_manifest([asset])

        with self.assertRaisesRegex(ValueError, "sha256 mismatch"):
            copy_raw.copy_assets(self.source, self.destination, self.manifest)
        self.assertFalse((self.destination / "资料.md").exists())

    def test_rejects_manifest_path_outside_roots(self):
        self.write_manifest(
            [{"bytes": 1, "paths": ["../outside.md"], "sha256": "0" * 64}]
        )

        with self.assertRaisesRegex(ValueError, "unsafe manifest path"):
            copy_raw.copy_assets(self.source, self.destination, self.manifest)

    def test_repairs_missing_path_only_when_hash_match_is_unique(self):
        asset = self.write_source("moved/资料.md", b"current")
        asset["paths"] = ["old/资料.md"]
        self.write_manifest([asset])

        repaired = copy_raw.repair_moved_paths(self.source, self.manifest)
        result = json.loads(self.manifest.read_text(encoding="utf-8"))

        self.assertEqual(repaired, 1)
        self.assertEqual(
            result["assets"][0]["paths"], [str(Path("moved") / "资料.md")]
        )

    def test_rejects_ambiguous_moved_path_repair(self):
        asset = self.write_source("first/资料.md", b"same")
        self.write_source("second/资料.md", b"same")
        asset["paths"] = ["old/资料.md"]
        self.write_manifest([asset])

        with self.assertRaisesRegex(ValueError, "multiple matches"):
            copy_raw.repair_moved_paths(self.source, self.manifest)

    def test_refreshes_only_listed_paths_and_regroups_current_content(self):
        first = self.write_source("first.md", b"current")
        second = self.write_source("nested/second.md", b"current")
        self.write_source("not-listed.md", b"excluded")
        first["bytes"] = 1
        first["sha256"] = "0" * 64
        first["paths"] = ["first.md", "nested/second.md"]
        self.write_manifest([first])

        result = copy_raw.refresh_listed_assets(self.source, self.manifest)

        self.assertEqual(result["asset_count"], 2)
        self.assertEqual(result["logical_document_count"], 1)
        self.assertEqual(result["assets"][0]["bytes"], len(b"current"))
        self.assertEqual(result["assets"][0]["sha256"], second["sha256"])
        self.assertEqual(
            result["assets"][0]["paths"],
            ["first.md", str(Path("nested") / "second.md")],
        )
        self.assertNotIn("not-listed.md", result["assets"][0]["paths"])


if __name__ == "__main__":
    unittest.main()
