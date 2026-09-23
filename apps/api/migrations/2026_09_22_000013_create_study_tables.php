<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

/**
 * 学习记录三张表：收藏、笔记、做题记录与进度。
 */
class CreateStudyTables extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->string('target_id', 191);
            $table->string('title', 191)->default('');
            $table->string('url', 512)->default('');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['user_id', 'target_type', 'target_id'], 'favorites_unique_target');
            $table->index(['user_id', 'target_type']);
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->string('target_id', 191);
            $table->string('title', 191)->default('');
            $table->text('content');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['user_id', 'target_type', 'target_id'], 'notes_target_index');
        });

        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('source_ref', 64)->default('');
            $table->string('question_ref', 64)->default('');
            $table->string('module', 64)->default('');
            $table->string('chosen', 16)->default('');
            $table->string('correct', 16)->default('');
            $table->boolean('is_right')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['user_id', 'source']);
            $table->index(['user_id', 'created_at'], 'attempts_user_created_index');
        });

        Schema::create('study_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 32);
            $table->string('ref', 191);
            $table->string('label', 191)->default('');
            $table->string('status', 16)->default('reading');
            $table->unsignedInteger('correct_count')->default(0);
            $table->unsignedInteger('wrong_count')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['user_id', 'scope', 'ref'], 'study_progress_unique_ref');
            $table->index(['user_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_progress');
        Schema::dropIfExists('attempts');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('favorites');
    }
}
