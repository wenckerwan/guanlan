<?php

declare(strict_types=1);

namespace App\Seeder;

use JsonException;
use RuntimeException;

final class DatasetManifestVerifier
{
    /** @var array<int, string> */
    private const REQUIRED_DATASETS = [
        'analysis_articles.json',
        'mistakes.json',
        'mocks.json',
        'papers.json',
        'predictions.json',
        'questions.json',
        'stats.json',
    ];

    /** @var array<string, true> */
    private array $verifiedBasePaths = [];

    public function verify(string $basePath = BASE_PATH): void
    {
        $absoluteBasePath = $this->absolutePath($basePath);
        if (isset($this->verifiedBasePaths[$absoluteBasePath])) {
            return;
        }

        $errors = [];
        $manifestPath = $absoluteBasePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dataset-manifest.json';
        $manifest = null;

        if (! is_file($manifestPath)) {
            $errors[] = 'storage/dataset-manifest.json: missing';
        } else {
            try {
                $manifest = $this->decodeJson($manifestPath);
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if (! is_array($manifest)) {
            $errors[] = 'storage/dataset-manifest.json: expected a JSON object';
            $this->throwIfInvalid($errors);
            return;
        }

        $this->compareField($errors, 'manifest.schema_version', 1, $manifest['schema_version'] ?? null, array_key_exists('schema_version', $manifest));

        $datasets = $manifest['datasets'] ?? null;
        if (! is_array($datasets)) {
            $errors[] = 'manifest.datasets: expected an object containing 8 datasets, actual ' . $this->formatValue($datasets);
            $datasets = [];
        }

        $totalBytes = 0;
        $totalItems = 0;
        $availableFiles = 0;

        foreach (self::REQUIRED_DATASETS as $name) {
            $expected = $datasets[$name] ?? null;
            if (! is_array($expected)) {
                $errors[] = $name . ': missing manifest entry';
                $expected = [];
            }

            $path = $absoluteBasePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'dataset' . DIRECTORY_SEPARATOR . $name;
            if (! is_file($path)) {
                $errors[] = $name . ': missing file';
                continue;
            }

            $availableFiles++;
            clearstatcache(true, $path);
            $bytes = filesize($path);
            $sha256 = hash_file('sha256', $path);
            if ($bytes === false || $sha256 === false) {
                $errors[] = $name . ': unable to inspect file';
                continue;
            }

            $totalBytes += $bytes;
            $this->compareField($errors, $name . '.bytes', $expected['bytes'] ?? null, $bytes, array_key_exists('bytes', $expected));
            $this->compareField($errors, $name . '.sha256', $expected['sha256'] ?? null, $sha256, array_key_exists('sha256', $expected));

            try {
                [$items, $groups] = $this->describeDataset($this->decodeJson($path));
                $totalItems += $items;
                $this->compareField($errors, $name . '.items', $expected['items'] ?? null, $items, array_key_exists('items', $expected));
                $this->compareField($errors, $name . '.groups', $expected['groups'] ?? null, $groups, array_key_exists('groups', $expected));
            } catch (RuntimeException $exception) {
                $errors[] = $name . ': ' . $exception->getMessage();
            }
        }

        $source = $manifest['source_manifest'] ?? null;
        if (! is_array($source)) {
            $errors[] = 'manifest.source_manifest: missing';
            $source = [];
        }

        $expectedSourcePath = 'storage/import-manifest.json';
        $this->compareField($errors, 'source_manifest.path', $expectedSourcePath, $source['path'] ?? null, array_key_exists('path', $source));

        $sourcePath = $absoluteBasePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'import-manifest.json';
        if (! is_file($sourcePath)) {
            $errors[] = $expectedSourcePath . ': missing file';
        } else {
            clearstatcache(true, $sourcePath);
            $sourceSha256 = hash_file('sha256', $sourcePath);
            if ($sourceSha256 === false) {
                $errors[] = $expectedSourcePath . ': unable to calculate sha256';
            } else {
                $this->compareField($errors, 'source_manifest.sha256', $source['sha256'] ?? null, $sourceSha256, array_key_exists('sha256', $source));
            }

            try {
                $sourcePayload = $this->decodeJson($sourcePath);
                if (! is_array($sourcePayload)) {
                    $errors[] = $expectedSourcePath . ': expected a JSON object';
                } else {
                    $this->compareField($errors, 'source_manifest.asset_count', $source['asset_count'] ?? null, $sourcePayload['asset_count'] ?? null, array_key_exists('asset_count', $source) && array_key_exists('asset_count', $sourcePayload));
                    $this->compareField($errors, 'source_manifest.logical_document_count', $source['logical_document_count'] ?? null, $sourcePayload['logical_document_count'] ?? null, array_key_exists('logical_document_count', $source) && array_key_exists('logical_document_count', $sourcePayload));
                }
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        $totals = $manifest['totals'] ?? null;
        if (! is_array($totals)) {
            $errors[] = 'manifest.totals: missing';
        } else {
            $this->compareField($errors, 'totals.bytes', $totals['bytes'] ?? null, $totalBytes, array_key_exists('bytes', $totals));
            $this->compareField($errors, 'totals.files', $totals['files'] ?? null, $availableFiles, array_key_exists('files', $totals));
            $this->compareField($errors, 'totals.items', $totals['items'] ?? null, $totalItems, array_key_exists('items', $totals));
        }

        $this->throwIfInvalid($errors);
        $this->verifiedBasePaths[$absoluteBasePath] = true;
    }

    private function absolutePath(string $basePath): string
    {
        $resolved = realpath($basePath);
        if ($resolved !== false) {
            return rtrim($resolved, DIRECTORY_SEPARATOR);
        }

        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\\/]{2})/', $basePath) === 1) {
            return rtrim($basePath, DIRECTORY_SEPARATOR);
        }

        return rtrim((string) getcwd(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($basePath, DIRECTORY_SEPARATOR);
    }

    /** @return mixed */
    private function decodeJson(string $path): mixed
    {
        try {
            return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($this->displayPath($path) . ': invalid JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** @return array{0: int, 1: array<string, int>} */
    private function describeDataset(mixed $payload): array
    {
        if (! is_array($payload)) {
            throw new RuntimeException('dataset: JSON root must be an array or object');
        }

        if (array_is_list($payload)) {
            return [count($payload), []];
        }

        $groups = [];
        $items = 0;
        foreach ($payload as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $groups[(string) $key] = count($value);
                $items += count($value);
            }
        }
        ksort($groups);

        return [$items, $groups];
    }

    /** @param array<int, string> $errors */
    private function compareField(array &$errors, string $field, mixed $expected, mixed $actual, bool $exists): void
    {
        if (! $exists || $expected !== $actual) {
            $errors[] = $field . ': expected ' . ($exists ? $this->formatValue($expected) : 'missing') . ', actual ' . $this->formatValue($actual);
        }
    }

    /** @param array<int, string> $errors */
    private function throwIfInvalid(array $errors): void
    {
        if ($errors !== []) {
            throw new RuntimeException("Dataset integrity check failed:\n- " . implode("\n- ", $errors));
        }
    }

    private function displayPath(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_string($value)) {
            return '"' . $value . '"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return var_export($value, true);
    }
}
