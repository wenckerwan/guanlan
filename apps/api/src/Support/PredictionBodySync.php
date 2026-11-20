<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class PredictionBodySync
{
    public const SOURCES = [
        '2027考研政治时政热点预测.md', '人民网2026考研政治时政精选.md',
        '人民网462314建党105周年专题考研政治精选.md', '上合组织专题_2026.md',
        '时政增补_20260922.md', '时政增补_20260928-29.md',
        '时政增补_世界人工智能大会讲话_马原大题_20260929.md',
        '时政增补_中美八点成果共识_20260927.md', '时政增补_生态环境法典_20260928.md',
        '2027时政考点预测_纯真题反推.md',
    ];
    private const FIELDS = ['title', 'summary', 'html', 'outline', 'word_count'];

    public static function values(array $row): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $row)) throw new RuntimeException("Missing field: $field");
            $values[$field] = $row[$field];
        }
        if (is_array($values['outline'])) {
            foreach ($values['outline'] as &$heading) ksort($heading);
        }
        return $values;
    }

    public static function plan(array $current, array $sources, ?string $only): array
    {
        $bySlug = array_column($current, null, 'slug');
        $seen = $files = $changes = $unchanged = $entries = [];
        foreach ($sources as $source) {
            $slug = $source['slug'] ?? '';
            $file = $source['source_file'] ?? '';
            if (!is_string($slug) || $slug === '' || isset($seen[$slug]) || isset($files[$file])
                || !in_array($file, self::SOURCES, true)) throw new RuntimeException('Unexpected or duplicate prediction source');
            $seen[$slug] = $files[$file] = true;
            if ($only !== null && $slug !== $only) continue;
            $row = $bySlug[$slug] ?? null;
            if (!$row || $row['source_file'] !== $file) throw new RuntimeException("Prediction source does not match live row: $slug");
            $after = self::values($source);
            if (!is_string($after['title']) || trim($after['title']) === '' || !is_string($after['summary'])
                || !is_string($after['html']) || trim($after['html']) === '' || !is_array($after['outline'])
                || $after['outline'] === [] || !is_int($after['word_count']) || $after['word_count'] < 1) {
                throw new RuntimeException("Invalid prediction content: $slug");
            }
            $before = self::values($row);
            $fields = array_keys(array_filter($after, fn ($v, $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
            if ($fields === []) {
                $unchanged[] = $slug;
                continue;
            }
            $changes[] = ['slug'=>$slug, 'fields'=>$fields];
            $entries[] = ['id'=>$row['id'], 'slug'=>$slug, 'source_file'=>$file, 'before'=>$before,
                'after'=>$after, 'old_html_sha256'=>hash('sha256', (string) $before['html'])];
        }
        if ($only === null ? count($files) !== count(self::SOURCES) : !isset($seen[$only])) {
            throw new RuntimeException('Requested prediction snapshot is incomplete');
        }
        return compact('changes', 'unchanged', 'entries');
    }

    public static function restore(array $current, array $entries): array
    {
        $rows = array_column($current, null, 'slug');
        $seen = $restores = [];
        foreach ($entries as $entry) {
            $slug = $entry['slug'] ?? '';
            $file = $entry['source_file'] ?? '';
            $row = $rows[$slug] ?? null;
            if (!$row || isset($seen[$slug]) || $row['id'] !== ($entry['id'] ?? null)
                || $row['source_file'] !== $file || !in_array($file, self::SOURCES, true)
                || self::values($row) !== self::values($entry['after'])) {
                throw new RuntimeException("Rollback conflict: $slug");
            }
            $before = self::values($entry['before']);
            if (hash('sha256', (string) $before['html']) !== ($entry['old_html_sha256'] ?? null)) {
                throw new RuntimeException("Invalid rollback receipt: $slug");
            }
            $seen[$slug] = true;
            $restores[] = ['id'=>$row['id'], 'after'=>$before];
        }
        return $restores;
    }
}
