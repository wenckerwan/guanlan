import unittest
from pathlib import Path
import sys
import tempfile

sys.path.insert(0, str(Path(__file__).parent))
import manifest


class ManifestPathsTest(unittest.TestCase):
    def test_output_and_source_stay_inside_project(self):
        project_root = Path(__file__).resolve().parents[2]
        self.assertEqual(manifest.ROOT, project_root)
        self.assertEqual(manifest.OUTPUT, project_root / "storage" / "import-manifest.json")
        self.assertEqual(manifest.SOURCE_ROOT, project_root / "storage" / "raw")

    def test_builds_manifest_from_explicit_raw_directory(self):
        with tempfile.TemporaryDirectory() as temporary:
            raw = Path(temporary)
            (raw / "nested").mkdir()
            (raw / "nested" / "资料.md").write_text("内容", encoding="utf-8")

            result = manifest.build_manifest(raw)

        self.assertEqual(result["asset_count"], 1)
        self.assertEqual(result["logical_document_count"], 1)
        self.assertEqual(
            result["assets"][0]["paths"], [str(Path("nested") / "资料.md")]
        )

    def test_builders_reference_only_shared_workspace_raw_root(self):
        ingest_dir = Path(__file__).resolve().parent
        for name in (
            "build_questions.py",
            "build_articles.py",
            "build_mistakes.py",
            "build_mocks.py",
        ):
            source = (ingest_dir / name).read_text(encoding="utf-8")
            self.assertNotIn("F:\\", source, name)
            self.assertIn("RAW_ROOT", source, name)

    def test_manifest_does_not_ingest_the_project_itself(self):
        result = manifest.build_manifest()
        self.assertEqual(result["asset_count"], 548)
        self.assertEqual(result["logical_document_count"], 418)
        self.assertTrue(all(not path.startswith("guanlan\\") for item in result["assets"] for path in item["paths"]))


if __name__ == "__main__":
    unittest.main()
