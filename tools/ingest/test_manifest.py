import unittest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).parent))
import manifest


class ManifestPathsTest(unittest.TestCase):
    def test_output_stays_inside_project_and_source_is_parent_materials_root(self):
        project_root = Path(__file__).resolve().parents[2]
        self.assertEqual(manifest.ROOT, project_root)
        self.assertEqual(manifest.OUTPUT, project_root / "storage" / "import-manifest.json")
        self.assertEqual(manifest.SOURCE_ROOT, project_root.parent)

    def test_manifest_does_not_ingest_the_project_itself(self):
        result = manifest.build_manifest()
        self.assertEqual(result["asset_count"], 177)
        self.assertTrue(all(not path.startswith("guanlan\\") for item in result["assets"] for path in item["paths"]))


if __name__ == "__main__":
    unittest.main()
