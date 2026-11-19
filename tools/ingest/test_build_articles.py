import io
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

with mock.patch.object(sys, "path", [str(Path(__file__).parent), *sys.path]):
    from tools.ingest import build_articles
from tools.ingest import dataset_manifest


class BuildArticlesTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.analysis = self.root / "raw"
        self.analysis.mkdir()
        self.out = self.root / "dataset"
        self.out.mkdir()
        self.source = self.root / "import-manifest.json"
        self.source.write_text(
            '{"asset_count":1,"logical_document_count":1}', encoding="utf-8"
        )
        self.manifest = self.root / "dataset-manifest.json"
        for name in dataset_manifest.REQUIRED_DATASETS:
            (self.out / name).write_bytes(b'[{"unchanged": true}]\r\n')
        for name in [
            *build_articles.ANALYSIS_CATEGORY,
            "选择题绝对错误选项规律v2_发行版.md",
            "选择题绝对错误选项规律v2.md",
            "README.md",
            "unlisted.md",
        ]:
            (self.analysis / name).write_text(
                "# Document\n\nBody stays intact.\n\n## Section\n\n"
                "### Detail\n\n#### Too deep\n",
                encoding="utf-8",
            )
        self.enter = mock.patch.multiple(
            build_articles,
            ANALYSIS_DIR=self.analysis,
            OUT=self.out,
            SOURCE_MANIFEST=self.source,
            DATASET_MANIFEST=self.manifest,
        )
        self.enter.start()
        self.addCleanup(self.enter.stop)

    def test_analysis_only_filters_sources_preserves_body_and_outline(self):
        build_articles.main(["--only", "analysis"])
        payload = json.loads((self.out / "analysis_articles.json").read_bytes())
        self.assertEqual(
            {item["source_file"] for item in payload},
            set(build_articles.ANALYSIS_CATEGORY),
        )
        self.assertEqual(len(payload), 11)
        release = [item for item in payload if item["release"]]
        self.assertEqual([(item["source_file"], item["slug"]) for item in release], [
            (build_articles.LEGACY_RELEASE_SOURCE, "2012-2026-649d1f95"),
        ])
        self.assertEqual(
            sum(item["category"] == "选择题规律" for item in payload), 4
        )
        for item in payload:
            self.assertEqual(item["outline"], [
                {"level": 2, "title": "Section"},
                {"level": 3, "title": "Detail"},
            ])
            self.assertIn("<p>Body stays intact.</p>", item["html"])
            self.assertNotIn("<h1>", item["html"])

    def test_analysis_only_refreshes_manifest_without_other_builders_or_writes(self):
        before = {path.name: path.read_bytes() for path in self.out.iterdir()}
        with (
            mock.patch.object(build_articles, "build_hotspots") as hotspots,
            mock.patch.object(build_articles, "build_predictions") as predictions,
        ):
            build_articles.main(["--only", "analysis"])
        hotspots.assert_not_called()
        predictions.assert_not_called()
        for name, contents in before.items():
            if name != "analysis_articles.json":
                self.assertEqual((self.out / name).read_bytes(), contents)
        for path in [self.out / "analysis_articles.json", self.manifest]:
            self.assertNotIn(b"\r", path.read_bytes())
            self.assertIn(b"\n", path.read_bytes())
        self.assertEqual(
            json.loads(self.manifest.read_bytes()),
            dataset_manifest.build_manifest(self.out, self.source),
        )

    def test_no_argument_main_keeps_full_build_without_manifest_refresh(self):
        with (
            mock.patch.object(build_articles, "build_hotspots", return_value=[{"hotspot": 1}]),
            mock.patch.object(build_articles, "build_predictions", return_value=[{"prediction": 1}]),
        ):
            build_articles.main()
        self.assertEqual(json.loads((self.out / "hotspots.json").read_bytes()), [{"hotspot": 1}])
        self.assertEqual(json.loads((self.out / "predictions.json").read_bytes()), [{"prediction": 1}])
        self.assertFalse(self.manifest.exists())

    def test_full_build_keeps_h1_in_hotspot_and_prediction_outlines(self):
        source = self.analysis / "选择题分析.md"
        with mock.patch.multiple(build_articles, HOTSPOT_FILES=[source], PREDICTION_FILES=[source]):
            build_articles.main()
        for filename in ("hotspots.json", "predictions.json"):
            with self.subTest(filename=filename):
                rows = json.loads((self.out / filename).read_bytes())
                self.assertEqual(rows[0]["outline"], [
                    {"level": 1, "title": "Document"},
                    {"level": 2, "title": "Section"},
                    {"level": 3, "title": "Detail"},
                ])

    def test_unknown_target_is_rejected_before_writing(self):
        before = (self.out / "analysis_articles.json").read_bytes()
        with mock.patch("sys.stderr", new=io.StringIO()), self.assertRaises(SystemExit) as error:
            build_articles.main(["--only", "hotspots"])
        self.assertEqual(error.exception.code, 2)
        self.assertEqual((self.out / "analysis_articles.json").read_bytes(), before)


if __name__ == "__main__":
    unittest.main()
