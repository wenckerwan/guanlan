<?php

declare(strict_types=1);

/**
 * ShizhengSimilarityService 纯算法测试：字符 bigram、IDF 加权、余弦排序。
 * 不依赖容器与数据库，直接 require 服务类（静态方法不碰 questions 表）。
 *
 * 跑法：php tests/ShizhengSimilarityTest.php
 * 绝对分数阈值（≥0.12 强 / <0.08 弱）依赖真实 316 题语料的 IDF，不在这里断言，
 * 由 deploy/check_exam_similarity.sh 拿 2026-09-30 的候选做回放校验。
 */

require dirname(__DIR__) . '/src/Service/ShizhengSimilarityService.php';

use App\Service\ShizhengSimilarityService as Sim;

$failures = [];
$check = static function (string $label, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
    }
};
$ok = static function (string $label, bool $cond) use (&$failures): void {
    if (! $cond) {
        $failures[] = $label . ': 断言失败';
    }
};

// ---------- grams ----------
$g = Sim::grams('统筹发展和安全');
$check('grams 数量（7 字得 6 个二元组）', count($g), 6);
$ok('grams 含「统筹」', isset($g['统筹']));
$ok('grams 含「安全」', isset($g['安全']));

// 标点必须断开，不能拼出跨标点的二元组
$p = Sim::grams('发展，安全');
$ok('标点不参与 bigram', ! isset($p['展，']) && ! isset($p['，安']));
$check('标点两侧各成一个词元串', array_keys($p), ['发展', '安全']);

// 全角/空白/英文大小写归一
$ok('大小写归一', Sim::grams('AI 人工智能') === Sim::grams('ai 人工智能'));
$check('纯符号无二元组', Sim::grams('——  ，。！'), []);
$check('单字不成二元组', Sim::grams('党'), []);

// 重复计数进 tf
$rep = Sim::grams('安全发展安全');
$check('重复 gram 计 tf', $rep['安全'] ?? 0, 2);

// ---------- candidateText 权重 ----------
$text = Sim::candidateText([
    'title' => '党建思想',
    'facts' => ['研讨会召开'],
    'fixed_phrases' => ['坚持'],
    'exam_points' => [],
    'traps' => [],
    '_keywords' => ['党建'],
]);
$ok('标题进入候选文本', str_contains($text, '党建思想'));
$ok('关键词进入候选文本', str_contains($text, '党建'));
$check('标题按权重重复 2 次', substr_count($text, '党建思想'), 2);
$check('facts 按权重 1 次', substr_count($text, '研讨会召开'), 1);
$check('_keywords 按权重 3 次', substr_count($text, '党建，') + substr_count($text, '党建 '), 3);
$check('空 payload 不炸', Sim::candidateText([]), '');

// ---------- 索引 + 排序 ----------
$docs = [
    ['id' => 1, 'year' => 2024, 'super_name' => '习思想与形策', 'stem_excerpt' => '党建思想',
        'tf' => Sim::grams('习近平党建思想是新时代党的建设理论成果，回答建设什么样的长期执政的马克思主义政党')],
    ['id' => 2, 'year' => 2023, 'super_name' => '形势与政策以及当代世界经济与政治', 'stem_excerpt' => '国家安全',
        'tf' => Sim::grams('总体国家安全观要求统筹发展和安全，既重视发展问题又重视安全问题')],
    ['id' => 3, 'year' => 2022, 'super_name' => '习思想与形策', 'stem_excerpt' => '中日关系',
        'tf' => Sim::grams('中日四个政治文件确立了和平友好条约精神，是处理两国关系的政治基础')],
    ['id' => 4, 'year' => 2021, 'super_name' => '形势与政策以及当代世界经济与政治', 'stem_excerpt' => '退税',
        'tf' => Sim::grams('增值税留抵退税政策缓解企业资金压力，属于积极财政政策工具')],
];
$index = Sim::buildIndex($docs);
$check('索引记录文档数', $index['n'], 4);

// 高频出现在所有文档的 gram，IDF 必须低于只出现在一篇里的 gram（用可控的合成小语料断言）
$syn = Sim::buildIndex([
    ['id' => 1, 'year' => 2024, 'super_name' => 'x', 'stem_excerpt' => '', 'tf' => Sim::grams('党的建设理论体系')],
    ['id' => 2, 'year' => 2023, 'super_name' => 'x', 'stem_excerpt' => '', 'tf' => Sim::grams('党的政治建设')],
    ['id' => 3, 'year' => 2022, 'super_name' => 'x', 'stem_excerpt' => '', 'tf' => Sim::grams('党的纪律')],
    ['id' => 4, 'year' => 2021, 'super_name' => 'x', 'stem_excerpt' => '', 'tf' => Sim::grams('党的作风')],
]);
$ok('「党的」出现在全部 4 篇', ($syn['idf']['党的'] ?? 0) > 0 && ($syn['idf']['理论'] ?? 0) > 0);
$ok('常见 gram 的 IDF 低于罕见 gram', $syn['idf']['党的'] < $syn['idf']['理论']);

// 与 doc2 同主题的候选，必须把 doc2 排在第一
$cand = Sim::vector(Sim::grams('统筹发展和安全，以高水平安全护航高质量发展'), $index['idf']);
$hits = Sim::cosineAgainstIndex($cand, $index);
$ok('命中非空', $hits !== []);
$check('主题相同的真题排第一', $hits[0]['id'], 2);
$ok('首命中分数不低于其余命中', $hits[0]['score'] >= ($hits[1]['score'] ?? 0));
$ok('首命中分数在 0~1 之间', $hits[0]['score'] > 0 && $hits[0]['score'] <= 1.0001);

// 与题池无关的候选：分数必须显著低于主题命中
$off = Sim::cosineAgainstIndex(Sim::vector(Sim::grams('秋天的第一杯奶茶在社交平台走红引发热议'), $index['idf']), $index);
$ok('无关文本分数低于主题命中', ($off[0]['score'] ?? 0) < $hits[0]['score']);
$ok('无关文本分数低于 0.3', ($off[0]['score'] ?? 0) < 0.3);

// 完全相同的文本 → 余弦 1
$same = Sim::vector($docs[1]['tf'], $index['idf']);
$sameHits = Sim::cosineAgainstIndex($same, $index);
$ok('同文本余弦接近 1', abs($sameHits[0]['score'] - 1.0) < 1e-6);
$check('同文本即该文档', $sameHits[0]['id'], 2);

// 空向量不炸
$check('空候选向量的命中为空', Sim::cosineAgainstIndex([], $index), []);

if ($failures !== []) {
    fwrite(STDERR, "ShizhengSimilarityTest: FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo "ShizhengSimilarityTest: PASS\n";
