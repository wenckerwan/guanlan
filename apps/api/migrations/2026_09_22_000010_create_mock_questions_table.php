<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateMockQuestionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('mock_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mock_id')->constrained('mocks')->cascadeOnDelete();
            $table->unsignedSmallInteger('no');
            $table->string('type', 16)->default('');
            $table->string('type_cn', 32)->default('');
            $table->decimal('score', 5, 1)->default(0);
            $table->string('module', 16)->default('');
            $table->string('module_name', 64)->default('');
            $table->string('kaodian', 191)->default('');
            $table->text('stem')->nullable();
            $table->json('options')->nullable();
            $table->string('answer', 16)->default('');
            $table->longText('analysis')->nullable();
            $table->unique(['mock_id', 'no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_questions');
    }
}
