<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateMistakeReviewsTable extends Migration
{
    public function up(): void
    {
        Schema::create('mistake_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mistake_item_id')->constrained('mistake_items')->cascadeOnDelete();
            $table->string('item_key', 128)->default('');

            // 复习状态
            $table->string('status', 16)->default('new'); // new, reviewing, mastered, snoozed
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('wrong_count')->default(0);

            // 最近一次复习
            $table->string('last_chosen', 16)->default('');
            $table->string('last_result', 16)->default(''); // correct, wrong
            $table->dateTime('last_reviewed_at')->nullable();
            $table->dateTime('next_review_at')->nullable();

            // 个人行动建议
            $table->text('personal_action')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            // 索引
            $table->unique(['user_id', 'mistake_item_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'next_review_at']);
            $table->index('item_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mistake_reviews');
    }
}
