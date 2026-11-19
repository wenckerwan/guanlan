<?php
declare(strict_types=1);
namespace App\Support;

/** Pure, reviewable policy for the November analysis release. No database writes. */
final class AnalysisReleasePlan
{
    private static function canonicalOutline(array $outline): array
    {
        foreach ($outline as &$entry) {
            if (is_array($entry)) {
                ksort($entry);
            }
        }
        return $outline;
    }

    public static function fingerprint(array $rows): string
    {
        usort($rows, fn(array $a, array $b) => (int)$a['id'] <=> (int)$b['id']);
        foreach ($rows as &$row) ksort($row);
        unset($row);
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function prepare(array $rows, array $sources): array
    {
        $plan = self::build($rows, $sources);
        $byId = array_column($rows, null, 'id');
        $plan['entries'] = array_map(static function (array $change) use ($byId): array {
            $row = $byId[$change['id']];
            $before = array_intersect_key($row, $change['fields']);
            if (isset($before['outline']) && is_string($before['outline'])) {
                $before['outline'] = json_decode($before['outline'], true, 512, JSON_THROW_ON_ERROR);
            }
            return [
                'id' => $change['id'], 'slug' => $change['slug'], 'revision' => (int) $row['revision'],
                'before' => $before, 'after' => $change['fields'],
            ];
        }, $plan['changes']);
        $plan['token'] = hash('sha256', self::fingerprint($rows)
            . json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $plan;
    }

    public static function rollback(array $rows, array $entries): array
    {
        $byId = array_column($rows, null, 'id');
        $seen = [];
        $changes = [];
        foreach ($entries as $entry) {
            $id = $entry['id'];
            $row = $byId[$id] ?? null;
            $slug = $entry['slug'];
            $permitted = in_array($slug, AnalysisRelease::RETIRED_SLUGS, true)
                ? ['status'] : ['outline'];
            if (isset($seen[$id]) || array_keys($entry['before']) !== $permitted
                || array_keys($entry['after']) !== $permitted
                || !$row || $row['slug'] !== $slug || (int) $row['revision'] !== $entry['revision'] + 1) {
                throw new \RuntimeException('回退版本冲突：'.$slug);
            }
            foreach ($entry['after'] as $field => $value) {
                $current = $row[$field] ?? null;
                if ($field === 'outline' && is_string($current)) {
                    $current = json_decode($current, true, 512, JSON_THROW_ON_ERROR);
                }
                if ($field === 'outline' && is_array($current) && is_array($value)) {
                    $current = self::canonicalOutline($current);
                    $value = self::canonicalOutline($value);
                }
                if ($current !== $value) {
                    throw new \RuntimeException('回退字段冲突：'.$slug);
                }
            }
            $seen[$id] = true;
            $changes[] = ['id' => $id, 'fields' => $entry['before']];
        }
        return $changes;
    }

    public static function build(array $rows, array $sources): array
    {
        $expected = array_column($sources, null, 'slug');
        $actual = array_column($rows, null, 'slug');
        $blockers = [];
        $changes = [];
        $skipped = [];
        if (count($expected) !== 11 || count($sources) !== 11 || !isset($expected[AnalysisRelease::RELEASE_SLUG])) {
            $blockers[] = '分析源数据不是预期的 11 篇发行快照';
        }
        if (count($actual) !== count($rows)) $blockers[] = '数据库存在重复 slug';
        foreach ($expected as $slug => $source) {
            if (!isset($actual[$slug])) $blockers[] = '缺少分析文章：'.$slug;
            elseif (($actual[$slug]['status'] ?? null) === 'hidden') $blockers[] = '保留文章处于隐藏状态：'.$slug;
        }
        if (isset($actual[AnalysisRelease::RELEASE_SLUG]) && empty($actual[AnalysisRelease::RELEASE_SLUG]['release'])) {
            $blockers[] = '发行版未标记 release';
        }
        $visible = 0;
        foreach ($rows as $row) {
            $slug = (string)$row['slug'];
            $fields = [];
            if (in_array($slug, AnalysisRelease::RETIRED_SLUGS, true)) {
                if (($row['status'] ?? null) !== 'hidden') $fields['status'] = 'hidden';
            } elseif (!isset($expected[$slug])) {
                $blockers[] = '存在未审核文章：'.$slug;
            } else {
                if (($row['status'] ?? null) !== 'hidden') ++$visible;
                $outline = is_array($row['outline'] ?? null) ? $row['outline'] : json_decode((string)($row['outline'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($outline) || !array_is_list($outline)) {
                    $blockers[] = '目录格式无效：'.$slug;
                    continue;
                }
                $hasH1 = count(array_filter($outline, fn($item) => is_array($item) && (int)($item['level'] ?? 0) === 1)) > 0;
                if ($hasH1) {
                    $source = $expected[$slug];
                    $withoutTitle = array_values(array_filter($outline, fn($item) => !is_array($item) || (int)($item['level'] ?? 0) !== 1));
                    $titleEntries = array_values(array_filter($outline, fn($item) => is_array($item) && (int)($item['level'] ?? 0) === 1));
                    $safe = ($row['content_source'] ?? 'dataset') === 'dataset'
                        && ($row['html'] ?? '') === $source['html']
                        && ($row['title'] ?? '') === $source['title']
                        && count($titleEntries) === 1
                        && ($outline[0]['level'] ?? null) === 1
                        && ($titleEntries[0]['title'] ?? '') === $source['title']
                        && self::canonicalOutline($withoutTitle) === self::canonicalOutline($source['outline']);
                    if ($safe) $fields['outline'] = $withoutTitle;
                    else $skipped[] = ['slug'=>$slug, 'reason'=>'目录或正文与已审核源稿不一致，保留现值'];
                }
            }
            if ($fields !== []) $changes[] = ['id'=>(int)$row['id'], 'slug'=>$slug, 'fields'=>$fields];
        }
        return ['blockers'=>$blockers,'changes'=>$changes,'skipped_outlines'=>$skipped,'visible_after'=>$visible];
    }
}
