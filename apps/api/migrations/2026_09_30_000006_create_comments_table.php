<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateCommentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('article_type', 16);
            $table->string('article_slug', 191);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('floor')->default(0);
            $table->text('content');
            $table->string('status', 16)->default('approved');
            $table->boolean('pinned')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['article_type', 'article_slug', 'status']);
            $table->index('user_id');
            $table->index('parent_id');
        });

        Schema::table('analysis_articles', function (Blueprint $table) {
            $table->string('comment_mode', 16)->default('open');
        });
        Schema::table('predictions', function (Blueprint $table) {
            $table->string('comment_mode', 16)->default('open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::table('analysis_articles', function (Blueprint $table) {
            $table->dropColumn('comment_mode');
        });
        Schema::table('predictions', function (Blueprint $table) {
            $table->dropColumn('comment_mode');
        });
    }
}
