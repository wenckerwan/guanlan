<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * 每日时政候选池：爬虫推送当天提炼的素材，后台 AI 筛选后发布到 hotspots。
 *
 * status 流转：pending → selected / rejected → published
 */
class CreateShizhengCandidatesTable extends Migration
{
    public function up(): void
    {
        Schema::create('shizheng_candidates', function (Blueprint $table) {
            $table->id();
            $table->date('publish_date');
            $table->string('title', 191);
            $table->string('source', 32)->default('');
            $table->string('channel', 64)->default('');
            $table->string('url', 512)->default('');
            $table->json('payload')->nullable();          // 提炼结果全文（facts/fixed_phrases/...）
            $table->string('status', 16)->default('pending');
            $table->string('ai_priority', 8)->default('');
            $table->string('ai_module', 16)->default('');
            $table->string('ai_reason', 255)->default('');
            $table->unsignedBigInteger('hotspot_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['publish_date', 'title']);
            $table->index(['publish_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shizheng_candidates');
    }
}
