<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateMistakeItemsTable extends Migration
{
    public function up(): void
    {
        Schema::create('mistake_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('mistake_students')->cascadeOnDelete();
            $table->string('module', 32)->default('');
            $table->string('chapter', 64)->default('');
            $table->unsignedTinyInteger('chapter_no')->default(0);
            $table->string('source_no', 16)->default('');
            $table->string('kaodian', 191)->default('');
            $table->text('stem')->nullable();
            $table->json('options')->nullable();
            $table->string('my_answer', 16)->default('');
            $table->string('correct_answer', 16)->default('');
            $table->string('q_type', 16)->default('');
            $table->string('error_type', 16)->default('');
            $table->text('action')->nullable();
            $table->integer('sort_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['student_id', 'module']);
            $table->index('error_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mistake_items');
    }
}
