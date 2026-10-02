<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * 给每日时政候选池加「真题相关度」三列。
 *
 * 由 ShizhengSimilarityService 在服务端本地算出（字符 bigram TF-IDF + 余弦相似度，
 * 语料为 questions 表的时政题池），不依赖爬虫侧、不出网、不用 embedding。
 * 目的：AI 筛选有真题证据可依，AI 不可用时的规则兜底也能按考试相关度排序
 * ——此前兜底只按爬虫自标的 priority，实测几乎等于随机，会漏选高相关、误发纯经济数据新闻。
 */
class AddExamSimilarityToShizhengCandidates extends Migration
{
    public function up(): void
    {
        Schema::table('shizheng_candidates', function (Blueprint $table) {
            $table->decimal('exam_sim', 5, 4)->default(0)->after('ai_reason');
            $table->decimal('exam_affinity', 5, 4)->default(0)->after('exam_sim');
            $table->json('exam_matches')->nullable()->after('exam_affinity');
            $table->index(['publish_date', 'exam_sim']);
        });
    }

    public function down(): void
    {
        Schema::table('shizheng_candidates', function (Blueprint $table) {
            $table->dropIndex(['publish_date', 'exam_sim']);
            $table->dropColumn(['exam_matches', 'exam_affinity', 'exam_sim']);
        });
    }
}
