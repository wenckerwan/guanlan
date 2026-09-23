<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * 真题分析补两列：
 * - release：是否为「发行版」定稿（同一标题可能有工作稿与发布稿两份）
 * - priority：星级优先级 S/A/B/C，用于列表排序
 */
class ExtendAnalysisArticlesTable extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_articles', function (Blueprint $table) {
            $table->boolean('release')->default(false)->after('category');
            $table->string('priority', 1)->default('B')->after('release');
            $table->index('priority');
            $table->index('release');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_articles', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropIndex(['release']);
            $table->dropColumn(['release', 'priority']);
        });
    }
}