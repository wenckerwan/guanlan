<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * 马原知识宇宙学习摘要表（每用户一行，绝对值覆盖）。
 * 详细学习记录权威在马原；本表仅收马原 outbox 推送的聚合摘要，供个人中心展示。
 * user_id 唯一；revision 单调递增防旧覆盖新；last_event_id 用于推送幂等。
 */
class CreateMayuanSummariesTable extends Migration
{
    public function up(): void
    {
        Schema::create('mayuan_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedInteger('revision')->default(0);
            $table->string('content_version', 191)->default('');
            $table->unsignedInteger('visited_concept_count')->default(0);
            $table->unsignedInteger('self_assessed_mastered_count')->default(0);
            $table->unsignedInteger('practice_attempt_count')->default(0);
            $table->unsignedInteger('practice_correct_count')->default(0);
            $table->unsignedInteger('due_review_count')->default(0);
            $table->string('last_event_id', 191)->default('');
            $table->dateTime('last_activity_at')->nullable();
            $table->json('resume_target')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mayuan_summaries');
    }
}
