<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddConsecutiveCorrectToMistakeReviewsTable extends Migration
{
    public function up(): void
    {
        Schema::table('mistake_reviews', function (Blueprint $table) {
            // 当前连续答对次数：答错清零，答对 +1；达到 3 判定已掌握
            $table->unsignedTinyInteger('consecutive_correct')->default(0)->after('wrong_count');
        });
    }

    public function down(): void
    {
        Schema::table('mistake_reviews', function (Blueprint $table) {
            $table->dropColumn('consecutive_correct');
        });
    }
}
