<?php

declare(strict_types=1);

use App\Seeder\DatasetManifestVerifier;

if (! defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

$autoloadPath = BASE_PATH . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require $autoloadPath;
}
if (! class_exists(DatasetManifestVerifier::class)) {
    require BASE_PATH . '/src/Seeder/DatasetManifestVerifier.php';
}

$fixtureRoot = sys_get_temp_dir() . '/guanlan-dataset-verifier-' . bin2hex(random_bytes(6));
$datasetRoot = $fixtureRoot . '/storage/dataset';
$sourceManifestPath = $fixtureRoot . '/storage/import-manifest.json';

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($entryPath) ? $removeDirectory($entryPath) : unlink($entryPath);
    }

    rmdir($path);
};

try {
    if (! is_dir($datasetRoot)) {
        mkdir($datasetRoot, 0777, true);
    }
    if (! is_dir(dirname($sourceManifestPath))) {
        mkdir(dirname($sourceManifestPath), 0777, true);
    }

    $datasets = [
        'analysis_articles.json' => [['id' => 1]],
        'hotspots.json' => [['id' => 2]],
        'mistakes.json' => ['items' => [['id' => 3]], 'handbooks' => [['id' => 4]]],
        'mocks.json' => [['id' => 5]],
        'papers.json' => [['id' => 6]],
        'predictions.json' => [['id' => 7]],
        'questions.json' => [['id' => 8]],
        'stats.json' => ['yearCounts' => [['year' => 2026]]],
    ];

    foreach ($datasets as $name => $payload) {
        file_put_contents($datasetRoot . '/' . $name, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    file_put_contents($sourceManifestPath, json_encode([
        'asset_count' => 1,
        'logical_document_count' => 1,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    $manifestPath = $datasetRoot . '/../dataset-manifest.json';

    // 纯 PHP 复刻 tools/ingest/dataset_manifest.py 的 build_manifest 算法，
    // 让本测试不依赖 python3（api 容器是纯 PHP 镜像）。字段口径与 verifier 一致。
    $buildFixtureManifest = static function (string $datasetDir, string $sourceManifestPath, string $outputPath): void {
        $required = [
            'analysis_articles.json', 'hotspots.json', 'mistakes.json', 'mocks.json',
            'papers.json', 'predictions.json', 'questions.json', 'stats.json',
        ];
        $source = json_decode((string) file_get_contents($sourceManifestPath), true, 512, JSON_THROW_ON_ERROR);
        $datasets = [];
        $totalItems = 0;
        $totalBytes = 0;
        foreach ($required as $name) {
            $path = $datasetDir . '/' . $name;
            $bytes = filesize($path);
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (array_is_list($payload)) {
                $items = count($payload);
                $groups = [];
            } else {
                $items = 0;
                $groups = [];
                foreach ($payload as $key => $value) {
                    if (is_array($value) && array_is_list($value)) {
                        $groups[(string) $key] = count($value);
                        $items += count($value);
                    }
                }
                ksort($groups);
            }
            $datasets[$name] = [
                'bytes' => $bytes,
                'groups' => $groups === [] ? new stdClass() : $groups,
                'items' => $items,
                'sha256' => hash_file('sha256', $path),
            ];
            $totalItems += $items;
            $totalBytes += $bytes;
        }
        file_put_contents($outputPath, json_encode([
            'schema_version' => 1,
            'source_manifest' => [
                'asset_count' => $source['asset_count'],
                'logical_document_count' => $source['logical_document_count'],
                'path' => 'storage/import-manifest.json',
                'sha256' => hash_file('sha256', $sourceManifestPath),
            ],
            'datasets' => $datasets,
            'totals' => [
                'bytes' => $totalBytes,
                'files' => count($required),
                'items' => $totalItems,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    };
    $buildFixtureManifest($datasetRoot, $sourceManifestPath, $manifestPath);

    $verifier = new DatasetManifestVerifier();
    $verifier->verify($fixtureRoot);

    file_put_contents($fixtureRoot . '/storage/dataset/questions.json', '[{"id":99}]');
    try {
        (new DatasetManifestVerifier())->verify($fixtureRoot);
        throw new RuntimeException('expected integrity failure');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'questions.json'));
        assert(str_contains($exception->getMessage(), 'sha256'));
    }

    $seederPaths = [
        dirname(__DIR__) . '/seeders/ArticleSeeder.php',
        dirname(__DIR__) . '/seeders/MistakeSeeder.php',
        dirname(__DIR__) . '/seeders/PaperQuestionSeeder.php',
    ];
    foreach ($seederPaths as $seederPath) {
        $source = (string) file_get_contents($seederPath);
        if (! preg_match('/public function run\(\): void\s*\{(?<body>.*?)\n    \}/s', $source, $matches)) {
            throw new RuntimeException('missing run method in ' . basename($seederPath));
        }

        $runBody = $matches['body'];
        $verificationPosition = strpos($runBody, '(new DatasetManifestVerifier())->verify();');
        if ($verificationPosition === false) {
            throw new RuntimeException('missing dataset verification in ' . basename($seederPath));
        }

        $firstOperationPosition = strlen($runBody);
        foreach (['$this->', 'Db::', 'DatasetReader::', '->query', 'file_get_contents'] as $operation) {
            $position = strpos($runBody, $operation);
            if ($position !== false) {
                $firstOperationPosition = min($firstOperationPosition, $position);
            }
        }
        if ($verificationPosition > $firstOperationPosition) {
            throw new RuntimeException('dataset verification occurs after first operation in ' . basename($seederPath));
        }
    }

    $dockerfile = (string) file_get_contents(dirname(__DIR__) . '/Dockerfile');
    $verificationCommand = 'php bin/verify-dataset.php &&';
    $migrationCommand = 'php bin/hyperf.php migrate --force';
    $verificationPosition = strpos($dockerfile, $verificationCommand);
    $migrationPosition = strpos($dockerfile, $migrationCommand);
    if ($verificationPosition === false || $migrationPosition === false || $verificationPosition > $migrationPosition) {
        throw new RuntimeException('Dockerfile must verify dataset before migration');
    }

    echo "DatasetManifestVerifierTest: PASS\n";
} finally {
    $removeDirectory($fixtureRoot);
}
