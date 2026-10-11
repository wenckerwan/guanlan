<?php
declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreatePracticeTables extends Migration
{
    public function up(): void
    {
        Schema::create('practice_banks', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 96)->unique();
            $table->string('title', 191);
            $table->string('category', 64);
            $table->unsignedInteger('schema_version');
            $table->string('content_version', 96);
            $table->char('source_sha256', 64);
            $table->string('status', 16)->default('draft');
            $table->unsignedSmallInteger('preview_count')->default(6);
            $table->dateTime('published_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('practice_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('practice_banks');
            $table->string('question_ref', 64);
            $table->char('question_version', 64);
            $table->string('type', 8);
            $table->string('module', 8);
            $table->text('stem');
            $table->json('options');
            $table->string('answer', 4);
            $table->longText('analysis');
            $table->text('event')->nullable();
            $table->text('knowledge')->nullable();
            $table->json('sources');
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['bank_id', 'question_ref', 'question_version'], 'practice_question_version_unique');
            $table->index(['bank_id', 'is_active', 'sort_order'], 'practice_question_active_index');
        });
        Schema::create('practice_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('bank_id')->constrained('practice_banks');
            $table->foreignId('question_id')->constrained('practice_questions');
            $table->char('client_event_id', 36);
            $table->string('chosen', 4);
            $table->string('correct_snapshot', 4);
            $table->boolean('is_right');
            $table->char('request_fingerprint', 64);
            $table->dateTime('created_at')->nullable();
            $table->unique(['user_id', 'client_event_id'], 'practice_attempt_event_unique');
            $table->index(['user_id', 'bank_id', 'question_id', 'id'], 'practice_attempt_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_attempts');
        Schema::dropIfExists('practice_questions');
        Schema::dropIfExists('practice_banks');
    }
}
