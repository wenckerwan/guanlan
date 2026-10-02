<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Question;

/**
 * 时政候选的「真题相关度」打分器：字符 bigram TF-IDF + 余弦相似度，纯本地计算。
 *
 * 为什么要有它（2026-09-30 实测的选择偏差）：AI 路径一旦降级，`screenByRule()` 只按
 * 爬虫自标的 `payload.priority` 排序，而爬虫在规则模式下把所有条目标成同一个「中」，
 * 排序近乎随机 —— 结果漏选了真题相关度最高的几条（党建思想研讨会 0.179、文化赋能治国理政
 * 0.157、高水平安全护航高质量发展 0.121、中日四个政治文件 0.121），却发布了纯经济数据
 * 新闻（增值税留抵退税 0.053、央行货币政策工具 0.076）。
 *
 * 为什么用 bigram 而不是 embedding：题库已在站点 DB 里（questions 时政题池 316 题），
 * 服务端具备本地计算条件；bigram TF-IDF 无需分词、无外部依赖、不出网，离线验证已能
 * 拉开 0.05 与 0.18 两档。接口做成可替换，日后要语义级再另立项。
 *
 * 语料与权重沿用离线验证脚本，保证与当时的复核结论可比。
 */
class ShizhengSimilarityService
{
    /** 时政题池口径：这两类覆盖形策与当代，是时政素材的命题来源 */
    private const SUPER_NAMES = ['习思想与形策', '形势与政策以及当代世界经济与政治'];

    /** 候选文本各字段权重（沿用离线脚本；标题与固定表述是强信号） */
    private const FIELD_WEIGHTS = [
        'title' => 2,
        'facts' => 1,
        'fixed_phrases' => 2,
        'exam_points' => 2,
        'traps' => 1,
        '_keywords' => 3,
    ];

    /** 进程内索引缓存：[指纹 => 索引]。题池不常变，命中率极高 */
    private static array $indexCache = [];

    /**
     * 给一条候选（爬虫推送的提炼条目）打分。
     *
     * @return array{exam_sim: float, exam_affinity: float, exam_matches: array}
     */
    public function scorePayload(array $payload): array
    {
        $index = $this->index();
        if ($index === null) {
            return ['exam_sim' => 0.0, 'exam_affinity' => 0.0, 'exam_matches' => []];
        }
        $vector = self::vector(self::grams(self::candidateText($payload)), $index['idf']);
        $hits = self::cosineAgainstIndex($vector, $index);

        $scores = array_column($hits, 'score');
        $top5 = array_slice($scores, 0, 5);

        return [
            'exam_sim' => round((float) ($scores[0] ?? 0), 4),
            'exam_affinity' => round($top5 === [] ? 0 : array_sum($top5) / count($top5), 4),
            'exam_matches' => array_map(static fn (array $h) => [
                'question_id' => $h['id'],
                'year' => $h['year'],
                'super_name' => $h['super_name'],
                'score' => round($h['score'], 4),
                'stem_excerpt' => $h['stem_excerpt'],
            ], array_slice($hits, 0, 3)),
        ];
    }

    /** 供 AI brief 用的真题摘要：「2024·形策 题干前 60 字（0.18）」 */
    public function matchHints(array $payload, int $limit = 3): array
    {
        $hints = [];
        foreach (array_slice($this->scorePayload($payload)['exam_matches'], 0, $limit) as $m) {
            $hints[] = sprintf('%d·%s %s（%.3f）',
                (int) $m['year'],
                mb_substr((string) $m['super_name'], 0, 6),
                (string) $m['stem_excerpt'],
                (float) $m['score']);
        }
        return $hints;
    }

    // ---------- 语料索引 ----------

    /**
     * 取（或复用）题池索引。题池指纹变了才重建。
     * 题池为空或库不可用时返回 null，调用方按「无相关度信号」降级，绝不抛断推送。
     */
    private function index(): ?array
    {
        $fingerprint = $this->corpusFingerprint();
        if ($fingerprint === null) {
            return null;
        }
        if (isset(self::$indexCache[$fingerprint])) {
            return self::$indexCache[$fingerprint];
        }

        $rows = Question::query()
            ->whereIn('super_name', self::SUPER_NAMES)
            ->get(['id', 'year', 'super_name', 'stem', 'material', 'analysis',
                'kaodian', 'trap', 'options'])
            ->all();
        if ($rows === []) {
            return null;
        }

        $docs = [];
        foreach ($rows as $q) {
            $docs[] = [
                'id' => (int) $q->id,
                'year' => (int) $q->year,
                'super_name' => (string) $q->super_name,
                'stem_excerpt' => mb_substr(trim((string) $q->stem), 0, 60),
                'tf' => self::grams(self::questionText($q)),
            ];
        }
        $index = self::buildIndex($docs);
        // 只保留最近两份，避免长驻 worker 里堆陈旧索引
        if (count(self::$indexCache) > 2) {
            self::$indexCache = [];
        }
        self::$indexCache[$fingerprint] = $index;
        return $index;
    }

    /** 题池指纹：条数 + 最后更新时间。题目改了（含后台编辑）下一批候选自动用新索引 */
    private function corpusFingerprint(): ?string
    {
        $row = Question::query()
            ->selectRaw('COUNT(*) AS n, MAX(updated_at) AS m')
            ->whereIn('super_name', self::SUPER_NAMES)
            ->first();
        $n = (int) ($row->n ?? 0);
        return $n > 0 ? $n . '|' . (string) ($row->m ?? '') : null;
    }

    /** 一道题的全部可比较文本：题干 + 材料 + 解析 + 考点 + 易错点 + 选项 */
    private static function questionText(Question $q): string
    {
        $options = is_array($q->options) ? $q->options : [];
        $optionTexts = [];
        foreach ($options as $o) {
            if (is_array($o)) {
                $optionTexts[] = (string) ($o['text'] ?? '');
            } elseif (is_scalar($o)) {
                $optionTexts[] = (string) $o;
            }
        }
        return implode('。', array_filter([
            (string) $q->stem,
            (string) $q->material,
            implode('；', $optionTexts),
            (string) $q->kaodian,
            (string) $q->trap,
            (string) $q->analysis,
        ], static fn ($s) => $s !== ''));
    }

    // ---------- 纯算法（可脱离数据库单测） ----------

    /** 候选文本：按字段权重拼接 */
    public static function candidateText(array $payload): string
    {
        $parts = [];
        foreach (self::FIELD_WEIGHTS as $field => $weight) {
            $values = $payload[$field] ?? '';
            if (is_string($values)) {
                $values = [$values];
            }
            if (! is_array($values)) {
                continue;
            }
            $text = trim(implode('。', array_filter(array_map(
                static fn ($v) => is_scalar($v) ? trim((string) $v) : '',
                $values
            ), static fn ($s) => $s !== '')));
            if ($text === '') {
                continue;
            }
            $parts[] = str_repeat($text . ' ', $weight);
        }
        return implode(' ', $parts);
    }

    /**
     * 中文字符 bigram 词袋：只保留汉字与字母数字，连续串内取二元组。
     * 不分词是刻意的——离线验证足够区分时政话题，且服务端无需任何词典或分词依赖。
     *
     * @return array<string, int> gram => 出现次数
     */
    public static function grams(string $text): array
    {
        $lower = mb_strtolower($text, 'UTF-8');
        // 只留汉字与字母数字，其余（标点、空白、引号、换行）作为切分边界，
        // 免得跨标点拼出「，要」这类无意义二元组。
        $runs = preg_split('/[^\p{Han}0-9a-z]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $grams = [];
        foreach ($runs as $run) {
            $len = mb_strlen($run);
            if ($len < 2) {
                continue;
            }
            for ($i = 0; $i + 1 < $len; $i++) {
                $g = mb_substr($run, $i, 2);
                $grams[$g] = ($grams[$g] ?? 0) + 1;
            }
        }
        return $grams;
    }

    /**
     * 由题池文档建索引：IDF、归一化向量、倒排 posting。
     *
     * @param array<int, array{id:int, year:int, super_name:string, stem_excerpt:string, tf:array<string,int>}> $docs
     * @return array{n:int, idf:array<string,float>, docs:array, postings:array<string, array<int,float>>}
     */
    public static function buildIndex(array $docs): array
    {
        $n = count($docs);
        $df = [];
        foreach ($docs as $d) {
            foreach (array_keys($d['tf']) as $g) {
                $df[$g] = ($df[$g] ?? 0) + 1;
            }
        }
        $idf = [];
        foreach ($df as $g => $c) {
            $idf[$g] = log(1 + $n / $c);
        }

        $postings = [];
        foreach ($docs as $i => $d) {
            $docs[$i]['vector'] = self::vector($d['tf'], $idf);
            foreach ($docs[$i]['vector'] as $g => $w) {
                $postings[$g][$i] = $w;
            }
        }
        // 倒排列表按权重降序，命中长尾 gram 时更快收敛
        foreach ($postings as $g => $list) {
            arsort($postings[$g]);
        }

        return ['n' => $n, 'idf' => $idf, 'docs' => $docs, 'postings' => $postings];
    }

    /** TF-IDF 加权 + L2 归一化（tf 取 sublinear：1+log(tf)） */
    public static function vector(array $tf, array $idf): array
    {
        $vec = [];
        $sumSq = 0.0;
        foreach ($tf as $g => $count) {
            if (! isset($idf[$g])) {
                continue;   // 题池未出现的 gram 不带信息量，丢掉即可保持向量稀疏
            }
            $w = (1 + log($count)) * $idf[$g];
            $vec[$g] = $w;
            $sumSq += $w * $w;
        }
        if ($sumSq <= 0) {
            return [];
        }
        $norm = sqrt($sumSq);
        foreach ($vec as $g => $w) {
            $vec[$g] = $w / $norm;
        }
        return $vec;
    }

    /**
     * 候选向量对全题池求余弦（文档向量已归一化，点积即余弦），返回降序命中。
     * 走倒排 posting 而非 316 次全向量比较：候选 gram 少、长尾 gram 的 posting 短。
     */
    public static function cosineAgainstIndex(array $vector, array $index): array
    {
        $accum = [];
        foreach ($vector as $g => $w) {
            if (! isset($index['postings'][$g])) {
                continue;
            }
            foreach ($index['postings'][$g] as $i => $dw) {
                $accum[$i] = ($accum[$i] ?? 0) + $w * $dw;
            }
        }
        arsort($accum);

        $hits = [];
        foreach ($accum as $i => $score) {
            if ($score <= 0) {
                continue;
            }
            $d = $index['docs'][$i];
            $hits[] = [
                'id' => $d['id'],
                'year' => $d['year'],
                'super_name' => $d['super_name'],
                'stem_excerpt' => $d['stem_excerpt'],
                'score' => (float) $score,
            ];
        }
        return $hits;
    }
}
